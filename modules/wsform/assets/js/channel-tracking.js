(function() {
    'use strict';

    /**
     * Configuration is provided via wp_localize_script (wsfAttributionConfig)
     */
    var CONFIG = window.wsfAttributionConfig;
    if (!CONFIG) return;

    var SEARCH_ENGINES = {
        'google': 'q', 'bing': 'q', 'yahoo': 'p', 'duckduckgo': 'q',
        'baidu': 'wd', 'yandex': 'text', 'ecosia': 'q', 'ask': 'q',
        'aol': 'q', 'startpage': 'query', 'qwant': 'q', 'brave': 'q'
    };

    var SOCIAL_NETWORKS = [
        'facebook', 'fb.com', 'instagram', 'twitter', 'x.com',
        'linkedin', 'pinterest', 'tiktok', 'snapchat', 'reddit',
        'tumblr', 'youtube', 'vimeo', 'whatsapp', 'telegram',
        't.me', 'threads.net'
    ];

    function setCookie(name, value, days) {
        if (value === null || value === undefined) value = '';
        var expires = '';
        if (days) {
            var date = new Date();
            date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
            expires = '; expires=' + date.toUTCString();
        }
        var domain = CONFIG.cookieDomain ? '; domain=' + CONFIG.cookieDomain : '';
        document.cookie = CONFIG.cookiePrefix + name + '=' + encodeURIComponent(value) + expires + '; path=' + CONFIG.cookiePath + domain + '; SameSite=Lax';
    }

    /**
     * Optimized cookie retrieval with internal caching during field population
     */
    function getCookie(name, cookieString) {
        var nameEQ = CONFIG.cookiePrefix + name + '=';
        var ca = (cookieString || document.cookie).split(';');
        for (var i = 0; i < ca.length; i++) {
            var c = ca[i];
            while (c.charAt(0) === ' ') c = c.substring(1, c.length);
            if (c.indexOf(nameEQ) === 0) return decodeURIComponent(c.substring(nameEQ.length, c.length));
        }
        return null;
    }

    function getUrlParam(param) {
        var urlParams = new URLSearchParams(window.location.search);
        return urlParams.get(param) || '';
    }

    function getReferrerHostname() {
        if (!document.referrer) return null;
        try {
            return new URL(document.referrer).hostname.toLowerCase();
        } catch (e) {
            return null;
        }
    }

    function isInternalReferrer(referrerHost) {
        if (!referrerHost) return true;
        var currentHost = window.location.hostname.toLowerCase();
        if (referrerHost === currentHost) return true;
        return referrerHost.endsWith('.' + currentHost) || currentHost.endsWith('.' + referrerHost);
    }

    function isSearchEngine(hostname) {
        if (!hostname) return false;
        var parts = hostname.split('.');
        for (var engine in SEARCH_ENGINES) {
            if (parts.indexOf(engine) !== -1) return engine;
        }
        return false;
    }

    function isSocialNetwork(hostname) {
        if (!hostname) return false;
        var parts = hostname.split('.');
        for (var i = 0; i < SOCIAL_NETWORKS.length; i++) {
            var network = SOCIAL_NETWORKS[i];
            var networkName = network.split('.')[0];
            if (parts.indexOf(networkName) !== -1) return networkName;
        }
        return false;
    }

    function determineCurrentSource() {
        var source = null, medium = null, campaign = '', term = '', content = '', gclid = '', fbclid = '';
        var utmSource = getUrlParam('utm_source'), utmMedium = getUrlParam('utm_medium');

        if (utmSource) {
            source = utmSource;
            medium = utmMedium || '(not set)';
            campaign = getUrlParam('utm_campaign');
            term = getUrlParam('utm_term');
            content = getUrlParam('utm_content');
        }

        gclid = getUrlParam('gclid');
        if (gclid) {
            if (!source) source = 'google';
            if (!medium) medium = 'cpc';
        }

        fbclid = getUrlParam('fbclid');
        if (fbclid && !source) {
            source = 'facebook';
            medium = 'cpc';
        }

        if (!source) {
            var referrerHost = getReferrerHostname();
            if (referrerHost && !isInternalReferrer(referrerHost)) {
                var searchEngine = isSearchEngine(referrerHost), socialNetwork = isSocialNetwork(referrerHost);
                if (searchEngine) {
                    source = searchEngine; medium = 'organic';
                } else if (socialNetwork) {
                    source = socialNetwork; medium = 'social';
                } else {
                    source = referrerHost; medium = 'referral';
                }
            }
        }

        if (source) {
            return { source: source, medium: medium, campaign: campaign, term: term, content: content, gclid: gclid, fbclid: fbclid };
        }
        return null;
    }

    function generateReferenceId(length) {
        var chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        var result = '';
        for (var i = 0; i < length; i++) {
            result += chars.charAt(Math.floor(Math.random() * chars.length));
        }
        return result;
    }

    function buildChatUrl(actionConfig, referenceId) {
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

    function fireButtonWebhook(actionId) {
        if (!CONFIG.ajaxUrl) return;
        var cookieString = document.cookie;
        var data = new FormData();
        data.append('action', 'frl_button_webhook');
        // Note: nonce intentionally omitted (see channel-tracking.php for explanation)
        data.append('action_id', actionId);
        data.append('page_url', window.location.href);
        data.append('referer', document.referrer || '');
        data.append('language', CONFIG.language || '');
        var cookieKeys = ['source', 'medium', 'campaign', 'term', 'content', 'gclid', 'fbclid', 'landing', 'reference_id'];
        for (var i = 0; i < cookieKeys.length; i++) {
            data.append(cookieKeys[i], getCookie(cookieKeys[i], cookieString) || '');
        }
        navigator.sendBeacon(CONFIG.ajaxUrl, data);
    }

    function attachChatButtonHandlers() {
        if (!Array.isArray(CONFIG.chatActions) || !CONFIG.chatActions.length) return;

        var referenceId = getCookie('reference_id');
        function bindChatHandler(element, actionConfig) {
            var initialRef = getCookie('reference_id') || referenceId || '';
            var initialUrl = buildChatUrl(actionConfig, initialRef);
            if (initialUrl) {
                element.setAttribute('data-button-url', initialUrl);
            }
            element.addEventListener('click', function(e) {
                var currentRef = getCookie('reference_id') || referenceId || '';
                var targetUrl = buildChatUrl(actionConfig, currentRef);
                if (targetUrl) {
                    element.setAttribute('data-button-url', targetUrl);
                    if (targetUrl.indexOf('mailto:') === 0) {
                        e.preventDefault();
                        window.location.href = targetUrl;
                    } else {
                        window.open(targetUrl, '_blank');
                    }
                }
                if (actionConfig.hasWebhook) {
                    fireButtonWebhook(actionConfig.id);
                }
            });
        }
        for (var i = 0; i < CONFIG.chatActions.length; i++) {
            var action = CONFIG.chatActions[i];
            if (!action || !action.id || !action.url) continue;

            var selector = '[data-action="' + action.id + '"]';
            var elements = document.querySelectorAll(selector);
            if (!elements.length) continue;

            for (var e = 0; e < elements.length; e++) {
                var el = elements[e];
                if (el.getAttribute('data-button-bounded') === '1') continue;
                el.setAttribute('data-button-bounded', '1');

                bindChatHandler(el, action);
            }
        }
    }

    function captureAttribution() {
        var currentSource = determineCurrentSource();
        if (currentSource) {
            var keys = ['source', 'medium', 'campaign', 'term', 'content', 'gclid', 'fbclid'];
            for (var i = 0; i < keys.length; i++) setCookie(keys[i], currentSource[keys[i]], CONFIG.cookieDays);
            setCookie('landing', window.location.href.split('?')[0], CONFIG.cookieDays);
        } else if (!getCookie('source')) {
            setCookie('source', '(direct)', CONFIG.cookieDays);
            setCookie('medium', '(none)', CONFIG.cookieDays);
            setCookie('campaign', '');
            setCookie('term', '');
            setCookie('content', '');
            setCookie('gclid', '');
            setCookie('fbclid', '');
            setCookie('landing', window.location.href.split('?')[0], CONFIG.cookieDays);
        }

        if (!getCookie('reference_id')) {
            var refIdLength = CONFIG.referenceIdLength || 10;
            setCookie('reference_id', generateReferenceId(refIdLength));
        }
    }

    /**
     * Optimized form population with cookie string caching
     */
    function populateFormFields() {
        var mapping = CONFIG.fieldMapping;
        var cookieString = document.cookie; // Cache document.cookie string once
        var keys = Array.isArray(CONFIG.keys) && CONFIG.keys.length
            ? CONFIG.keys
            : ['source', 'medium', 'campaign', 'term', 'content', 'gclid', 'fbclid', 'landing'];
        var defaults = { 'source': '(direct)', 'medium': '(none)' };

        for (var i = 0; i < keys.length; i++) {
            var key = keys[i];
            var fieldName = mapping[key] || (CONFIG.fieldPrefix + key);
            var value = getCookie(key, cookieString) || (defaults[key] || '');
            
            // Perform targeted queries (broadened to ensure detection)
            var selectors = [
                '[name="' + fieldName + '"]',
                '[name$="[' + fieldName + ']"]',
                '[data-name="' + fieldName + '"]'
            ];
            
            for (var s = 0; s < selectors.length; s++) {
                var query = selectors[s];
                var elements = document.querySelectorAll(query);
                
                for (var e = 0; e < elements.length; e++) {
                    var el = elements[e];
                    
                    // Only update if value is different to minimize DOM churn
                    if (el.value !== value || el.getAttribute('value') !== value) {
                        el.value = value;
                        el.setAttribute('value', value);
                        
                        // WS Form specific state attribute
                        if (el.hasAttribute('data-wsf-value')) {
                            el.setAttribute('data-wsf-value', value);
                        }

                        // Dispatch events to notify form logic
                        try {
                            el.dispatchEvent(new Event('input', { bubbles: true }));
                            el.dispatchEvent(new Event('change', { bubbles: true }));
                        } catch (e) {}
                    }
                }
            }
        }
    }

    var debounceTimer;
    function debouncedPopulate() {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(populateFormFields, 100);
    }

    function init() {
        captureAttribution();
        
        // Initial populations
        populateFormFields();
        attachChatButtonHandlers();
        
        // Retry after a short delay in case WS Form is slow to render its HTML
        setTimeout(populateFormFields, 1000);
        setTimeout(populateFormFields, 3000);
        setTimeout(attachChatButtonHandlers, 1000);
        setTimeout(attachChatButtonHandlers, 3000);

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', populateFormFields);
        } else {
            populateFormFields();
        }
        document.addEventListener('wsf-rendered', function() {
            debouncedPopulate();
            attachChatButtonHandlers();
        });
        
        if (typeof MutationObserver !== 'undefined') {
            var observer = new MutationObserver(function(mutations) {
                for (var i = 0; i < mutations.length; i++) {
                    if (mutations[i].addedNodes.length > 0) {
                        // Check if any added nodes might contain inputs
                        var nodes = mutations[i].addedNodes;
                        for (var n = 0; n < nodes.length; n++) {
                            if (nodes[n].nodeType === 1) { // Element node
                                debouncedPopulate();
                                return;
                            }
                        }
                    }
                }
            });
            observer.observe(document.body, { childList: true, subtree: true });
        }
    }

    init();
})();