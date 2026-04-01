/**
 * Tag Validator JavaScript
 * Handles AJAX tag validation functionality
 */
(function($) {
    'use strict';

    $(document).ready(function() {
        // Clean up URL on page load
        if (window.history && window.history.replaceState) {
            // Get the base URL without any query parameters
            var baseUrl = window.location.href.split('?')[0];
            
            // Add back only the essential WordPress admin parameter
            baseUrl += '?page=' + frlTagValidator.pluginPage;
            
            // Replace the current URL without refreshing
            window.history.replaceState({}, document.title, baseUrl);
        }
        
        // Handle validator button click
        $('#tag-validator-button').on('click', function(e) {
            e.preventDefault();
            
            var urlToValidate = $('#tag_validator_url').val().trim(),
                resultsContainer = $('#tag-validator-results-container');
                
            // Validate URL before proceeding
            if (!urlToValidate) {
                resultsContainer.html('<div class="error">Please enter a valid URL to validate.</div>');
                return;
            }
            
            // Ensure URL has proper protocol
            if (!/^https?:\/\//i.test(urlToValidate)) {
                urlToValidate = 'https://' + urlToValidate;
            }
            
            // Show loading indicator
            resultsContainer.html('<div class="tag-validator-loading-results">Loading validation results...</div>');
            
            // Get a clean base URL - just keep the WordPress admin parameter
            var baseUrl = window.location.href.split('?')[0] + '?page=' + frlTagValidator.pluginPage;
            
            // Use POST to avoid URL encoding issues
            $.ajax({
                url: baseUrl,
                type: 'POST',
                data: {
                    frl_tag_validator_url: urlToValidate
                },
                success: function(response) {
                    // Extract only the results container from the response
                    var resultsHtml = $(response).find('#tag-validator-results-container').html();
                    
                    // Check if we got valid results
                    if (!resultsHtml) {
                        resultsContainer.html('<div class="error">Error parsing response from server. Please try again.</div>');
                        return;
                    }
                    
                    // Update the results container
                    resultsContainer.html(resultsHtml);
                    
                    // Trigger the validator loaded event to initialize Prism and toggle buttons
                    // Pass the results container as an argument so handlers can access it directly
                    $(document).trigger('frl_content_loaded', [resultsContainer[0]]);
                },
                error: function() {
                    resultsContainer.html('<div class="error">Error communicating with the server.</div>');
                }
            });
        });
    });
})(jQuery);
