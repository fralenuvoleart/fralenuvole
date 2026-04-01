(function (wp) {
	if (!wp || !wp.blocks) { return; }

	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var useRef = wp.element.useRef;
	var registerBlockType = wp.blocks.registerBlockType;
	var TextControl = wp.components.TextControl;
	var SelectControl = wp.components.SelectControl;
	var InspectorControls = wp.blockEditor ? wp.blockEditor.InspectorControls : wp.editor.InspectorControls;
	var PanelBody = wp.components.PanelBody;
	var Spinner = wp.components.Spinner;
	var apiFetch = wp.apiFetch;

	// --- Config from PHP ---
	var CFG = window.FRL_ICONS_CFG || {};
	var ICONS_BASE = CFG.iconsBaseUrl || '';
	var REST_ICONS_ABS = CFG.restIcons || '';
	var ROOTS = Array.isArray(CFG.roots) ? CFG.roots.slice() : [];
	var COUNTER = CFG.counter || '';

function formatIconLabel(path) {
    if (typeof path !== 'string' || !path) { return ''; }
    var label = path.replace(/\.svg$/i, '');
    var parts = label.split('/');
    return parts.map(function (part) {
        return part.replace(/[-_]/g, ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); });
    }).join(' / ');
}

function IconVisual(props) {
    var icon = props.icon || '';
    var size = props.size || 24;
    if (!icon || !ICONS_BASE) { return null; }
    var style = {
        '--frl-icon-url': 'url(' + (ICONS_BASE + icon) + ')',
        maskImage: 'var(--frl-icon-url)',
        WebkitMaskImage: 'var(--frl-icon-url)',
        maskRepeat: 'no-repeat',
        WebkitMaskRepeat: 'no-repeat',
        maskSize: 'contain',
        WebkitMaskSize: 'contain',
        maskPosition: 'center',
        WebkitMaskPosition: 'center',
        backgroundRepeat: 'no-repeat',
        backgroundSize: 'contain',
        backgroundPosition: 'center',
        backgroundColor: 'currentColor',
        display: 'inline-block',
        width: size + 'px',
        height: size + 'px'
    };
    return wp.element.createElement('span', { className: 'frl-icon frl-icon--editor', style: style, role: 'img', 'aria-label': formatIconLabel(icon), title: formatIconLabel(icon) });
}

	function apiPathWithArgs(basePath, args) {
		return wp.url.addQueryArgs(basePath, args || {});
	}

	function getRestPath() {
		// Prefer localized absolute endpoint; convert to apiFetch path
		if (REST_ICONS_ABS && typeof wpApiSettings !== 'undefined' && wpApiSettings.root) {
			if (REST_ICONS_ABS.indexOf(wpApiSettings.root) === 0) {
				return REST_ICONS_ABS.substring(wpApiSettings.root.length - 1); // ensure leading '/'
			}
		}
		return '/frl/v1/icons';
	}

	function fetchIcons(query, page, pageSize, activeRoot) {
		var path = getRestPath();
		var params = { search: query || '', page: page || 1, pageSize: pageSize || 30 };
		if (ROOTS.length) {
			if (activeRoot) { params.root = activeRoot; }
			else { params.exclude_roots = ROOTS; }
		}
		var fullPath = apiPathWithArgs(path, params);
		return apiFetch({ path: fullPath })
			.then(function (res) {
				if (res && res.results) {
					return { items: res.results, more: !!(res.pagination && res.pagination.more) };
				}
				return { items: [], more: false };
			})
			.catch(function () { return { items: [], more: false }; });
	}

	function IconResults(props) {
		var items = props.items || [];
		var onPick = props.onPick || function () {};
		var loading = !!props.loading;
		var onLoadMore = props.onLoadMore || function () {};
		var hasMore = !!props.hasMore;
		var selected = props.selected || '';
		var base = ICONS_BASE;
		var listRef = useRef(null);
		var readyRef = useRef(false);

		function onScroll(e) {
			var elx = e.currentTarget;
			if (!elx) { return; }
			var nearBottom = (elx.scrollTop + elx.clientHeight) >= (elx.scrollHeight - 40);
			if (readyRef.current && elx.scrollTop > 0 && nearBottom && hasMore && !loading) {
				onLoadMore();
			}
		}

		useEffect(function(){ readyRef.current = true; }, []);

		return el('div', {
			className: 'frl-icon-results',
			style: { maxHeight: '320px', overflowY: 'auto', border: '1px solid #dcdcde', borderRadius: '2px' },
			onScroll: onScroll, ref: listRef
		}, [
			items.length === 0 && !loading ? el('div', { key: 'empty', style: { padding: '8px' } }, 'No results') : null,
			items.map(function (it) {
				var isSel = selected === it.id;
				return el('div', {
					key: it.id,
					className: 'frl-icon-item' + (isSel ? ' is-selected' : ''),
					style: { display: 'flex', alignItems: 'center', gap: '8px', padding: '6px 8px', cursor: 'pointer', background: isSel ? '#f0f6ff' : 'transparent' },
					onClick: function () { onPick(it.id); }
				}, [
					COUNTER && it.id === COUNTER ? el('span', { className: 'frl-icon-badge', style: { width:'20px', height:'20px', display:'inline-flex', alignItems:'center', justifyContent:'center', border:'2px solid #c4a26e', borderRadius:'999px', fontSize:'12px', lineHeight:1, color:'#c4a26e', background:'#fff' } }, '1') : el('img', { alt: '', src: base ? (base + it.id) : '', style: { width: '20px', height: '20px', objectFit: 'contain', flexShrink: 0 } }),
					el('span', { style: { fontSize: '12px', lineHeight: '1.4' } }, it.text)
				]);
			}),
			loading ? el('div', { key: 'loading', style: { padding: '8px', display: 'flex', alignItems: 'center', gap: '6px' } }, [el(Spinner, {}), el('span', null, 'Loading…')]) : null
		]);
	}

	function IconSelector(props) {
		var value = props.value || '';
		var onChange = props.onChange || function(){};
		var _a = useState(''), search = _a[0], setSearch = _a[1];
		var _b = useState([]), items = _b[0], setItems = _b[1];
		var _c = useState(1), page = _c[0], setPage = _c[1];
		var _d = useState(false), loading = _d[0], setLoading = _d[1];
		var _e = useState(false), more = _e[0], setMore = _e[1];
		var _f = useState(''), activeRoot = _f[0], setActiveRoot = _f[1];
    var _g = useState(false), opened = _g[0], setOpened = _g[1];
		var debounceRef = useRef(null);

		// Load page 1 when search/root changes AND the panel is opened
		useEffect(function () {
			if (!opened) { return; }
			var active = true;
			if (debounceRef.current) { clearTimeout(debounceRef.current); }
			debounceRef.current = setTimeout(function(){
				setLoading(true);
				setPage(1);
				fetchIcons(search, 1, 30, activeRoot).then(function (res) {
					if (!active) { return; }
					var arr = res.items || [];
					// Prepend Counter in default tab
					if (!activeRoot && COUNTER) {
						var seen = arr.some(function(it){ return it && it.id === COUNTER; });
						if (!seen) { arr = [{ id: COUNTER, text: 'Auto Number Counter' }].concat(arr); }
					}
					setItems(arr);
					setMore(!!res.more);
					setLoading(false);
				});
			}, 250);
			return function () { active = false; if (debounceRef.current) { clearTimeout(debounceRef.current); } };
		}, [search, activeRoot, opened]);

        function loadMore() {
			if (loading || !more) { return; }
			var nextPage = page + 1;
			setLoading(true);
			fetchIcons(search, nextPage, 30, activeRoot).then(function (res) {
				setItems(items.concat(res.items));
				setMore(!!res.more);
				setPage(nextPage);
				setLoading(false);
			});
		}

        function closePicker() {
            setOpened(false);
        }

		function RootTabs() {
			var tabs = [{ key: '', label: 'Icons' }].concat(ROOTS.map(function(r){ return { key: r, label: r.split(/[-_]/).map(function(p){return p.charAt(0).toUpperCase()+p.slice(1)}).join(' ') }; }));
			return el('div', { className: 'frl-icon-tabs', style: { display:'flex', gap:'8px', borderBottom:'1px solid #dcdcde', marginBottom:'8px' } }, tabs.map(function(t){
				var isActive = t.key === activeRoot;
				return el('button', {
					key: t.key || 'root-default',
					type: 'button',
					className: 'frl-icon-tab' + (isActive ? ' active' : ''),
					style: { appearance:'none', background:'none', border:0, padding:'6px 10px', borderBottom:'2px solid ' + (isActive ? '#2271b1' : 'transparent'), color: isActive ? '#2271b1' : '#50575e', cursor:'pointer' },
					onClick: function(){ setActiveRoot(t.key); }
				}, t.label);
			}));
		}

        return el('div', { className: 'frl-icon-selector' }, [
            !opened ? null : el('div', { key: 'picker-head', style: { display:'flex', justifyContent:'space-between', alignItems:'center', marginBottom:'6px' } }, [
                el('strong', null, 'Choose an icon'),
                el('button', { type:'button', className:'button button-secondary', onClick: closePicker }, 'Close')
            ]),
            opened ? el(RootTabs, {}) : null,
            opened ? el(TextControl, {
                key: 'search',
                label: 'Search icons',
                value: search,
                onChange: function (v) { setSearch(v); }
            }) : null,
            opened ? el(IconResults, {
				key: 'results',
				items: items,
				selected: value,
				loading: loading,
				hasMore: more,
                onPick: function (id) { onChange(id); closePicker(); },
				onLoadMore: loadMore
            }) : el('div', { key: 'cta', style: { marginTop:'6px', display:'flex', gap:'6px', alignItems:'center' } }, [
                el('button', { type: 'button', className: 'button', onClick: function(){ setOpened(true); } }, 'Browse icons'),
                value ? el('button', { type: 'button', className: 'button button-secondary', onClick: function(){ onChange(''); } }, 'Remove') : null
            ])
		]);
	}

    function Preview(props) {
        var icon = props.icon || '';
        if (!icon) { return null; }
        return el('div', { className: 'frl-icon-preview' }, el(IconVisual, { icon: icon, size: 24 }));
    }

	registerBlockType('frl/icon', {
		title: 'FRL Icon',
		category: 'widgets',
		icon: 'format-image',
		supports: { html: false },
		attributes: {
			icon: { type: 'string', default: '' },
			mode: { type: 'string', default: 'default' },
			title: { type: 'string', default: '' },
			className: { type: 'string' }
		},
        edit: function (props) {
            var attrs = props.attributes;
            function clearIcon() { props.setAttributes({ icon: '' }); }
            var label = attrs.icon ? formatIconLabel(attrs.icon) : 'No icon selected';

            return el('div', { className: props.className }, [
                el(InspectorControls, {}, [
                    el(PanelBody, { title: 'Icon', initialOpen: true }, [
                        el('div', { key:'current', style: { display:'flex', alignItems:'center', gap:'8px', marginBottom:'8px' } }, [
                            el(Preview, { icon: attrs.icon }),
                            el('div', null, el('div', { style:{ fontWeight:600 } }, label), attrs.icon ? el('code', null, attrs.icon) : null)
                        ]),
                        el(IconSelector, {
                            value: attrs.icon,
                            onChange: function (v) { props.setAttributes({ icon: v }); }
                        }),
                        el(SelectControl, {
                            label: 'Mode',
                            value: attrs.mode,
                            options: [
                                { label: 'Default (config)', value: 'default' },
                                { label: 'Inline SVG', value: 'inline' },
                                { label: 'URL (string)', value: 'url' }
                            ],
                            onChange: function (v) { props.setAttributes({ mode: v }); }
                        }),
                        el(TextControl, {
                            label: 'Title (accessibility)',
                            value: attrs.title,
                            onChange: function (v) { props.setAttributes({ title: v }); }
                        })
                    ])
                ]),
                el('div', { className: 'frl-icon-block' }, [
                    attrs.icon ? el(IconVisual, { key: 'icon', icon: attrs.icon, size: 28 }) : el('div', { key: 'empty-label' }, 'Select an icon…')
                ])
            ]);
        },
		save: function () { return null; }
	});
})(window.wp);
