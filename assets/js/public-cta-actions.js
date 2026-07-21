(function() {
    'use strict';

    var CONFIG = window.frlChannelTrackingConfig;
    if (!CONFIG || !Array.isArray(CONFIG.ctaActions) || !CONFIG.ctaActions.length) return;

    // Minimal duplicated getCookie() reader — intentional small duplication,
    // these two JS files are separate IIFEs with no shared scope.
    function getCookie(name) {
        var nameEQ = CONFIG.cookiePrefix + name + '=';
        var ca = document.cookie.split(';');
        for (var i = 0; i < ca.length; i++) {
            var c = ca[i];
            while (c.charAt(0) === ' ') c = c.substring(1, c.length);
            if (c.indexOf(nameEQ) === 0) return decodeURIComponent(c.substring(nameEQ.length, c.length));
        }
        return null;
    }

    function buildCtaUrl(actionConfig, referenceId) {
        if (!actionConfig || !actionConfig.url) return '';
        var url = actionConfig.url;

        if (actionConfig.template) {
            var template = actionConfig.template;
            
            // 1. Replace reference_id
            template = template.replace(/{reference_id}/g, referenceId || '');

            // 2. Replace {field-data-name:xxx} with value from element having data-name="xxx"
            template = template.replace(/{field-data-name:([^}]+)}/g, function(match, fieldName) {
                var selector = '[data-name="' + fieldName + '"]';
                var el = document.querySelector(selector);
                return el ? (el.value || '') : '';
            });

            // 3. Inject into URL
            url = url.replace('{template}', encodeURIComponent(template));
        }

        if (actionConfig.subject) {
            url = url.replace('{subject}', encodeURIComponent(actionConfig.subject));
        }

        return url;
    }

    function fireCtaWebhook(actionId) {
        if (!CONFIG.ajaxUrl) return;
        var data = new FormData();
        data.append('action', 'frl_cta_webhook');
        // Note: nonce intentionally omitted (see channel-tracking.php for explanation)
        data.append('action_id', actionId);
        data.append('page_url', window.location.href);
        data.append('referer', document.referrer || '');
        data.append('language', CONFIG.language || '');
        var cookieKeys = ['source', 'medium', 'campaign', 'term', 'content', 'gclid', 'fbclid', 'landing', 'reference_id'];
        for (var i = 0; i < cookieKeys.length; i++) {
            data.append(cookieKeys[i], getCookie(cookieKeys[i]) || '');
        }
        
        // Use fetch with keepalive instead of sendBeacon for better reliability and error handling
        if (window.fetch) {
            fetch(CONFIG.ajaxUrl, {
                method: 'POST',
                body: data,
                keepalive: true
            }).catch(function(error) {
                // Queue for retry on next page load if failed
                try {
                    var queue = JSON.parse(sessionStorage.getItem('frl_cta_queue') || '[]');
                    queue.push({
                        actionId: actionId,
                        timestamp: new Date().getTime()
                    });
                    sessionStorage.setItem('frl_cta_queue', JSON.stringify(queue));
                } catch (e) {}
            });
        } else {
            // Fallback for older browsers
            navigator.sendBeacon(CONFIG.ajaxUrl, data);
        }
    }

    // Process any queued webhooks from previous failed attempts
    function processQueuedWebhooks() {
        try {
            var queue = JSON.parse(sessionStorage.getItem('frl_cta_queue') || '[]');
            if (queue.length > 0) {
                sessionStorage.removeItem('frl_cta_queue');
                // Only retry events less than 24 hours old
                var now = new Date().getTime();
                for (var i = 0; i < queue.length; i++) {
                    if (now - queue[i].timestamp < 86400000) {
                        fireCtaWebhook(queue[i].actionId);
                    }
                }
            }
        } catch (e) {}
    }

    function attachCtaHandlers() {
        if (!Array.isArray(CONFIG.ctaActions) || !CONFIG.ctaActions.length) return;

        var referenceId = getCookie('reference_id');
        function bindCtaHandler(element, actionConfig) {
            var initialRef = getCookie('reference_id') || referenceId || '';
            var initialUrl = buildCtaUrl(actionConfig, initialRef);
            if (initialUrl) {
                element.setAttribute('data-cta-url', initialUrl);
            }
            element.addEventListener('click', function(e) {
                var currentRef = getCookie('reference_id') || referenceId || '';
                var targetUrl = buildCtaUrl(actionConfig, currentRef);
                if (targetUrl) {
                    // Element may be <a> or <button> — ALWAYS take manual control
                    // of navigation, not only for mailto: as the original code did.
                    // Without this, an <a> CTA with a real or placeholder href would
                    // additionally trigger native browser navigation alongside the
                    // JS-driven window.open()/location.href below (double-navigation bug).
                    e.preventDefault();
                    element.setAttribute('data-cta-url', targetUrl);
                    if (targetUrl.indexOf('mailto:') === 0) {
                        window.location.href = targetUrl;
                    } else {
                        window.open(targetUrl, '_blank');
                    }
                }
                if (actionConfig.hasWebhook) {
                    fireCtaWebhook(actionConfig.action_id);
                }
            });
        }
        for (var i = 0; i < CONFIG.ctaActions.length; i++) {
            var action = CONFIG.ctaActions[i];
            if (!action || !action.action_id || !action.url) continue;

            var selector = '[data-action="' + action.action_id + '"]';
            var elements = document.querySelectorAll(selector);
            if (!elements.length) continue;

            for (var e = 0; e < elements.length; e++) {
                var el = elements[e];
                if (el.getAttribute('data-cta-bound') === '1') continue;
                el.setAttribute('data-cta-bound', '1');

                bindCtaHandler(el, action);
            }
        }
    }

    // Init: bind immediately + retries for late-rendered elements
    processQueuedWebhooks();
    attachCtaHandlers();
    setTimeout(attachCtaHandlers, 1000);
    setTimeout(attachCtaHandlers, 3000);
    document.addEventListener('wsf-rendered', function() {
        attachCtaHandlers();
    });
})();
