/**
 * Bulk Resave Manager JavaScript
 * Handles AJAX operations and UI updates for the bulk resave functionality
 */
(function ($) {
    'use strict';

    // Bulk resave manager object
    const BulkResaveManager = {
        // Configuration
        config: {
            processingInterval: 1000, // 1 second between batch processing
            statusCheckInterval: 2000, // 2 seconds between status checks
            maxRetries: 3
        },

        // State variables
        isProcessing: false,
        processingTimer: null,
        statusTimer: null,
        currentNonce: null,
        retryCount: 0,

        // DOM elements
        elements: {
            startButton: null,
            cancelButton: null,
            clearButton: null,
            regularPostTypeSelect: null,
            batchSizeSelect: null,
            progressSection: null
        },

        /**
         * Initialize the bulk resave manager
         */
        init: function () {
            this.bindElements();
            this.bindEvents();
            this.initializeNonce();
            this.checkForActiveProcess();
        },

        /**
         * Bind DOM elements
         */
        bindElements: function () {
            this.elements.startButton = $('#start-resave');
            this.elements.cancelButton = $('#cancel-resave');
            this.elements.clearButton = $('#clear-progress');
            this.elements.regularPostTypeSelect = $('#regular_post_type_select');
            this.elements.batchSizeSelect = $('#batch_size_select');
            this.elements.progressSection = $('#progress-section');



            if (this.elements.regularPostTypeSelect.length === 0) {
                return false;
            }
        },

        /**
         * Bind event handlers
         */
        bindEvents: function () {
            // Start resave
            if (this.elements.startButton && this.elements.startButton.length > 0) {
                this.elements.startButton.on('click', (e) => {
                    e.preventDefault();
                    this.startResave();
                });
            }

            // Cancel resave
            this.elements.cancelButton.on('click', (e) => {
                e.preventDefault();
                this.cancelResave();
            });

            // Clear progress
            this.elements.clearButton.on('click', (e) => {
                e.preventDefault();
                this.clearProgress();
            });

            // Post type change validation and mutual exclusion
            this.elements.regularPostTypeSelect.on('change', () => {
                this.validateForm();
            });
        },

        /**
         * Initialize nonce from inline script or meta tag
         */
        initializeNonce: function () {
            // Try to get nonce from WordPress inline script
            if (typeof bulkResaveAjax !== 'undefined' && bulkResaveAjax.nonce) {
                this.currentNonce = bulkResaveAjax.nonce;
            } else {
                // Fallback: create a nonce using WordPress nonce system
                this.currentNonce = $('#_wpnonce').val() || wp.ajax.settings.nonce;
            }
        },

        /**
         * Check if there's an active resave process
         */
        checkForActiveProcess: function () {
            if (this.elements.progressSection.is(':visible') &&
                this.elements.progressSection.find('.status-running').length > 0) {
                this.isProcessing = true;
                this.updateUIForRunningState();
                this.startStatusChecking();
            }
        },

        /**
         * Validate form before starting
         */
        validateForm: function () {
            // Check if elements exist and were found
            if (!this.elements.regularPostTypeSelect || this.elements.regularPostTypeSelect.length === 0) {
                return false;
            }

            const regularPostType = (this.elements.regularPostTypeSelect.val() || '').trim();
            const isValid = regularPostType !== '';

            if (this.elements.startButton && this.elements.startButton.length > 0) {
                this.elements.startButton.prop('disabled', !isValid || this.isProcessing);
            }

            return isValid;
        },

        /**
         * Get the selected post type from either dropdown
         */
        getSelectedPostType: function () {
            let regularPostType = '';

            if (this.elements.regularPostTypeSelect && this.elements.regularPostTypeSelect.length > 0) {
                regularPostType = this.elements.regularPostTypeSelect.val() || '';
            }

            return regularPostType || '';
        },

        /**
         * Start the resave process
         */
        startResave: function () {
            if (this.isProcessing) {
                return;
            }

            const isValid = this.validateForm();

            if (!isValid) {
                this.showMessage('Please select a post type before starting the resave process.', 'warning');
                return;
            }

            const postType = this.getSelectedPostType();

            if (!postType) {
                this.showMessage('Please select a post type before starting the resave process.', 'warning');
                return;
            }

            const batchSize = parseInt(this.elements.batchSizeSelect.val()) || 20;
            const includeDrafts = $('#include_drafts').is(':checked');

            this.showMessage('Starting resave process...', 'info');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'frl_post_ajax_bulk_resave_start',
                    post_type: postType,
                    batch_size: batchSize,
                    include_drafts: includeDrafts,
                    nonce: this.currentNonce
                },
                success: (response) => {
                    if (response.success) {
                        this.currentNonce = response.data.new_nonce;
                        this.isProcessing = true;
                        this.updateUIForRunningState();
                        this.updateProgress(response.data.progress, response.data.progress_html);
                        this.showMessage(response.data.message, 'success');
                        this.startProcessing();
                    } else {
                        this.showMessage(response.data.message || 'Failed to start resave process.', 'error');
                    }
                },
                error: (xhr, status, error) => {
                    this.showMessage('Network error: ' + error, 'error');
                }
            });
        },

        /**
         * Start processing batches
         */
        startProcessing: function () {
            this.processingTimer = setTimeout(() => {
                this.processBatch();
            }, this.config.processingInterval);
        },

        /**
         * Process a single batch
         */
        processBatch: function () {
            if (!this.isProcessing) {
                return;
            }

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'frl_post_ajax_bulk_resave_process',
                    nonce: this.currentNonce
                },
                success: (response) => {
                    if (response.success) {
                        this.currentNonce = response.data.new_nonce;
                        this.updateProgress(response.data.progress, response.data.progress_html);
                        this.retryCount = 0; // Reset retry count on success

                        if (response.data.completed) {
                            this.completeProcess(response.data.message);
                        } else {
                            this.showMessage(response.data.message, 'info');
                            // Continue processing
                            this.startProcessing();
                        }
                    } else {
                        this.handleProcessingError(response.data.message || 'Failed to process batch.');
                    }
                },
                error: (xhr, status, error) => {
                    this.handleProcessingError('Network error: ' + error);
                }
            });
        },

        /**
         * Handle processing errors with retry logic
         */
        handleProcessingError: function (message) {
            this.retryCount++;

            if (this.retryCount < this.config.maxRetries) {
                this.showMessage(`Error occurred, retrying... (${this.retryCount}/${this.config.maxRetries})`, 'warning');
                // Retry after a longer delay
                this.processingTimer = setTimeout(() => {
                    this.processBatch();
                }, this.config.processingInterval * 2);
            } else {
                this.showMessage(message + ' Maximum retries exceeded.', 'error');
                this.stopProcessing();
            }
        },

        /**
         * Complete the resave process
         */
        completeProcess: function (message) {
            this.isProcessing = false;
            this.clearTimers();
            this.updateUIForCompletedState();
            this.showMessage(message, 'success');
        },

        /**
         * Cancel the resave process
         */
        cancelResave: function () {
            if (!this.isProcessing) {
                return;
            }

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'frl_post_ajax_bulk_resave_cancel',
                    nonce: this.currentNonce
                },
                success: (response) => {
                    if (response.success) {
                        this.currentNonce = response.data.new_nonce;
                        this.stopProcessing();
                        this.showMessage(response.data.message, 'info');
                    } else {
                        this.showMessage(response.data.message || 'Failed to cancel resave process.', 'error');
                    }
                },
                error: (xhr, status, error) => {
                    this.showMessage('Network error: ' + error, 'error');
                }
            });
        },

        /**
         * Stop processing and update UI
         */
        stopProcessing: function () {
            this.isProcessing = false;
            this.clearTimers();
            this.updateUIForStoppedState();
        },

        /**
         * Clear progress data
         */
        clearProgress: function () {
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'frl_post_ajax_bulk_resave_clear',
                    nonce: this.currentNonce
                },
                success: (response) => {
                    if (response.success) {
                        this.currentNonce = response.data.new_nonce;
                        // Replace with empty state HTML instead of hiding
                        if (response.data.progress_html) {
                            this.elements.progressSection.html(response.data.progress_html);
                        }
                        this.updateUIForIdleState();
                        this.showMessage(response.data.message, 'success');
                    } else {
                        this.showMessage(response.data.message || 'Failed to clear progress.', 'error');
                    }
                },
                error: (xhr, status, error) => {
                    this.showMessage('Network error: ' + error, 'error');
                }
            });
        },

        /**
         * Start checking status periodically
         */
        startStatusChecking: function () {
            this.statusTimer = setInterval(() => {
                this.checkStatus();
            }, this.config.statusCheckInterval);
        },

        /**
         * Check current status
         */
        checkStatus: function () {
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'frl_post_ajax_bulk_resave_status',
                    nonce: this.currentNonce
                },
                success: (response) => {
                    if (response.success) {
                        this.currentNonce = response.data.new_nonce;
                        if (response.data.progress) {
                            this.updateProgress(response.data.progress, response.data.progress_html);
                        }
                    }
                }
            });
        },

        /**
         * Update progress display
         */
        updateProgress: function (progress, progressHtml) {
            if (!progress) {
                return;
            }

            // If we have progress HTML, populate the section with it
            if (progressHtml) {
                this.elements.progressSection.html(progressHtml);
            }

            // Progress section should always be visible
            if (!this.elements.progressSection.is(':visible')) {
                this.elements.progressSection.show();
            }
        },

        /**
         * Update UI for running state
         */
        updateUIForRunningState: function () {
            this.elements.startButton.prop('disabled', true).hide();
            this.elements.cancelButton.show();
            this.elements.regularPostTypeSelect.prop('disabled', true);
            this.elements.batchSizeSelect.prop('disabled', true);
            $('#include_drafts').prop('disabled', true);
        },

        /**
         * Update UI for completed state
         */
        updateUIForCompletedState: function () {
            this.elements.startButton.prop('disabled', false).show();
            this.elements.cancelButton.hide();
            this.elements.regularPostTypeSelect.prop('disabled', false);
            this.elements.batchSizeSelect.prop('disabled', false);
            $('#include_drafts').prop('disabled', false);
        },

        /**
         * Update UI for stopped state
         */
        updateUIForStoppedState: function () {
            this.updateUIForCompletedState();
        },

        /**
         * Update UI for idle state
         */
        updateUIForIdleState: function () {
            this.updateUIForCompletedState();
            this.validateForm();
        },

        /**
         * Clear all timers
         */
        clearTimers: function () {
            if (this.processingTimer) {
                clearTimeout(this.processingTimer);
                this.processingTimer = null;
            }
            if (this.statusTimer) {
                clearInterval(this.statusTimer);
                this.statusTimer = null;
            }
        },

        /**
 * Show a message to the user inside the progress container
 */
        showMessage: function (message, type) {
            // Get the progress messages container inside the progress container
            const messagesContainer = $('#progress-section .progress-messages');
            if (messagesContainer.length === 0) {
                return;
            }

            // Determine message styling based on type
            let messageClass = 'progress-message ';
            switch (type) {
                case 'success':
                    messageClass += 'message-success';
                    break;
                case 'error':
                    messageClass += 'message-error';
                    break;
                case 'warning':
                    messageClass += 'message-warning';
                    break;
                default:
                    messageClass += 'message-info';
            }

            // Remove any existing messages
            messagesContainer.find('.progress-message').remove();

            // Create message element
            const messageElement = $('<div class="' + messageClass + '"><p>' + message + '</p></div>');

            // Insert into the messages container
            messagesContainer.append(messageElement);

            // Auto-dismiss after 5 seconds for non-error messages
            if (type !== 'error') {
                setTimeout(() => {
                    messageElement.fadeOut();
                }, 5000);
            }
        }
    };

    // Initialize when document is ready
    $(document).ready(function () {
        BulkResaveManager.init();
    });

    // Cleanup on page unload
    $(window).on('beforeunload', function () {
        BulkResaveManager.clearTimers();
    });

})(jQuery);
