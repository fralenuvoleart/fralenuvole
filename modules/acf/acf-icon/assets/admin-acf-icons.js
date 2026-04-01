(function($) {

    function formatIconLabel(path) {
        if (typeof path !== 'string' || path === '') {
            return '';
        }
        var label = path.replace('.svg', '');
        var parts = label.split('/');
        return parts.map(function(part) {
            return part.replace(/[-_]/g, ' ').replace(/\b\w/g, function(c) { return c.toUpperCase(); });
        }).join(' / ');
    }

    function templateResult(state, iconBaseUrl) {
        if (!state.id) {
            return state.text;
        }
        if (typeof FRL_ICONS_CFG !== 'undefined' && FRL_ICONS_CFG.counter === state.id) {
            var $c = $('<span class="frl-icon-option"></span>');
            var $badge = $('<span class="frl-icon-badge"></span>').text('1');
            $c.append($badge).append(document.createTextNode(' ' + (state.text || '')));
            return $c;
        }
        var $wrap = $('<span class="frl-icon-option"></span>');
        $('<img>', { alt: '', src: iconBaseUrl + state.id }).appendTo($wrap);
        $wrap.append(document.createTextNode(' ' + (state.text || '')));
        return $wrap;
    }

    function templateSelection(state, iconBaseUrl) {
        if (!state.id) {
            return state.text;
        }
        if (typeof FRL_ICONS_CFG !== 'undefined' && FRL_ICONS_CFG.counter === state.id) {
            var $cs = $('<span class="frl-icon-selected"></span>');
            var $badge = $('<span class="frl-icon-badge"></span>').text('1');
            var $label = $('<span class="frl-icon-label"></span>').text(state.text || '');
            $cs.append($badge).append($label);
            return $cs;
        }
        var $wrap = $('<span class="frl-icon-selected"></span>');
        $('<img>', { alt: '', src: iconBaseUrl + state.id }).appendTo($wrap);
        var $label = $('<span class="frl-icon-label"></span>').text(state.text || '');
        $wrap.append($label);
        return $wrap;
    }

    function initializeSelect2(field) {
        var $select = field.$el.find('select');
        if (!$select.length) {
            return;
        }

        // Re-entrancy guard: skip if already initialized
        if ($select.data('frlIconInit') || $select.hasClass('select2-hidden-accessible')) {
            return;
        }

        var $preview = field.$el.find('.frl-acf-icon-preview');
        var $container = field.$el.find('.acf-input');
        var iconBaseUrl = $preview.data('base') || '';
        var roots = (window.FRL_ICONS_CFG && Array.isArray(FRL_ICONS_CFG.roots)) ? FRL_ICONS_CFG.roots.slice() : [];

        // Add Icons/Flags tabs if not present
        var $tabs = $container.find('.frl-icon-tabs');
        if (!$tabs.length) {
            $tabs = $('<div class="frl-icon-tabs" role="tablist" aria-label="Icon source"></div>');
            var iconsRoot = String(iconBaseUrl).replace(/\/+$/, '').split('/').slice(-1)[0] || 'icons';
            var iconsLabel = iconsRoot.split(/[\-_]/).map(function(part){ return part.charAt(0).toUpperCase() + part.slice(1); }).join(' ');
            var extraRoots = roots;
            var $tabIcons = $('<button type="button" class="frl-icon-tab active" role="tab" aria-selected="true"></button>').attr('data-root', '').text(iconsLabel);
            $tabs.append($tabIcons);
            extraRoots.forEach(function(root){
                if (typeof root !== 'string' || !root) return;
                var label = root.split(/[\-_]/).map(function(part){ return part.charAt(0).toUpperCase() + part.slice(1); }).join(' ');
                var $btn = $('<button type="button" class="frl-icon-tab" role="tab" aria-selected="false"></button>').attr('data-root', root).text(label);
                $tabs.append($btn);
            });
            $container.prepend($tabs);
            $select.data('frlIconRoot', '');
            $tabs.on('click', '.frl-icon-tab', function(){
                var root = $(this).attr('data-root') || '';
                $tabs.find('.frl-icon-tab').removeClass('active').attr('aria-selected', 'false');
                $(this).addClass('active').attr('aria-selected', 'true');
                $select.data('frlIconRoot', root);
                // Do not clear current selection; let it persist when switching tabs
                togglePreviewScopeClass();
            });
        }

        // The REST URLs and nonce are localized and available in `window.FRL_ICONS_CFG`
        var restUrl = (window.FRL_ICONS_CFG && FRL_ICONS_CFG.restIcons) || (((window.FRL_ICONS_CFG && FRL_ICONS_CFG.restRoot) || '/wp-json/frl/v1/').replace(/\/+$/, '') + '/icons');
        var nonce = (window.FRL_ICONS_CFG && FRL_ICONS_CFG.nonce) || '';

        var valueToInject = $preview.attr('data-current-value');
        if (valueToInject && $select.find('option[value="' + valueToInject + '"]').length === 0) {
            var $option = $('<option></option>').val(valueToInject).text(formatIconLabel(valueToInject)).prop('selected', true);
            $select.append($option);
        }

        // Shared page-level transport cache for identical requests across multiple fields
        var pageCache = window.FRL_ICONS_PAGE_CACHE || (window.FRL_ICONS_PAGE_CACHE = {});

        // Determine allowClear from select attributes/options to avoid relying on ACF field model
        var allowNullAttr = parseInt($select.attr('data-allow_null'), 10);
        var hasEmptyOption = $select.find('option[value=""]').length > 0;
        var allowClear = !!(allowNullAttr || hasEmptyOption);
        var placeholder = $select.attr('data-placeholder') || (field.get && field.get('placeholder')) || 'Select an icon';

        $select.select2({
            ajax: {
                url: restUrl,
                dataType: 'json',
                delay: 250,
                headers: { 'X-WP-Nonce': nonce },
                data: function(params) {
                    var activeRoot = $select.data('frlIconRoot') || '';
                    var payload = {
                        search: params.term || '',
                        page: params.page || 1,
                        pageSize: 30
                    };
                    var extraRoots = roots;
                    if (extraRoots.length) {
                        if (activeRoot) {
                            payload.root = activeRoot;
                        } else {
                            payload.exclude_roots = extraRoots;
                        }
                    }
                    return payload;
                },
                transport: function (params, success, failure) {
                    var q = params.data.search || '';
                    var p = params.data.page || 1;
                    var activeRoot = ($select.data('frlIconRoot') || '');
                    var cacheKey = (activeRoot || 'default') + '|' + q + '|' + p;
                    if (pageCache[cacheKey]) {
                        success(pageCache[cacheKey]);
                        return;
                    }
                    var $request = $.ajax(params);
                    $request.then(function(data){
                        pageCache[cacheKey] = data;
                        success(data);
                    }, failure);
                    return $request;
                },
                processResults: function(data, params) {
                    params.page = params.page || 1;
                    var results = data.results || [];
                    // Prepend Counter only on the default/base tab (no active root)
                    var activeRoot = $select.data('frlIconRoot') || '';
                    if (!activeRoot && typeof FRL_ICONS_CFG !== 'undefined' && FRL_ICONS_CFG.counter) {
                        results = [{ id: FRL_ICONS_CFG.counter, text: 'Auto Number Counter' }].concat(results);
                    }
                    return { results: results, pagination: { more: !!(data.pagination && data.pagination.more) } };
                },
                cache: true
            },
            minimumInputLength: 0,
            allowClear: allowClear,
            placeholder: placeholder,
            containerCssClass: 'frl-icons',
            dropdownCssClass: 'frl-icons',
            // escapeMarkup is irrelevant when returning DOM nodes; remove passthrough to avoid unsafe future string templates
            templateResult: function(state) { return templateResult(state, iconBaseUrl); },
            templateSelection: function(state) { return templateSelection(state, iconBaseUrl); }
        });

        // Mark as initialized to prevent duplicate setup
        $select.data('frlIconInit', true);

        $select.off('change.frlIcon select2:clear.frlIcon').on('change.frlIcon select2:clear.frlIcon', function() {
             var value = $select.val() || '';
            if (!value) {
                $preview.empty();
                $preview.removeClass('is-out-of-scope');
                return;
            }
            if (typeof FRL_ICONS_CFG !== 'undefined' && FRL_ICONS_CFG.counter === value) {
                var $badge = $('<span class="frl-icon-badge"></span>').text('1');
                $preview.empty().append($badge);
                return;
            }
            var src = iconBaseUrl + value;
            var $img = $preview.find('img');
            if ($img.length) {
                $img.attr('src', src);
            } else {
                var $newImg = $('<img>', { alt: '', src: src });
                $preview.empty().append($newImg);
            }
            togglePreviewScopeClass();
        });

        function togglePreviewScopeClass() {
            var activeRoot = $select.data('frlIconRoot') || '';
            var val = $select.val() || '';
            if (!val) { $preview.removeClass('is-out-of-scope'); return; }
            var extraRoots = roots;
            var isInExtra = false;
            if (extraRoots.length) {
                for (var i=0;i<extraRoots.length;i++) {
                    var r = String(extraRoots[i] || '');
                    if (!r) continue;
                    var re = new RegExp('^' + r.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\/');
                    if (re.test(String(val))) { isInExtra = true; break; }
                }
            }
            var outOfScope = (activeRoot && !isInExtra) || (!activeRoot && isInExtra);
            $preview.toggleClass('is-out-of-scope', !!outOfScope);
        }

        // Initial state
        togglePreviewScopeClass();
    }

    if (window.acf) {
        acf.addAction('ready_field/type=frl_icon', initializeSelect2);
        acf.addAction('append_field/type=frl_icon', initializeSelect2);
    }

})(jQuery);
