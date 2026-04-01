/**
 * Fralenuvole – ultra-light scroll state classes on <html>
 * Classes:
 *  - frl-is-top
 *  - frl-is-scroll
 *  - frl-is-scroll-up
 *  - frl-is-scroll-down
 *  - frl-has-hero
 *
 * Performance:
 *  - Passive scroll listener enqueues a single rAF per frame
 *  - Reads scrollTop once; writes classList only on state change
 */
(() => {
	const root = document.documentElement;
	const scroller = document.scrollingElement || root;

	// Configurable selectors
	const SEL_HEADER = 'header.site-header';
	const SEL_HERO = '#hero';

	let lastY = scroller.scrollTop || 0;
	let ticking = false;
	let dir = 0; // -1 up, 1 down, 0 neutral
	const THRESH = 2;
	let scrollActiveTid = 0;
	const STOP_DELAY = 100; // ms – balanced; 120–160ms works well

	// Track last applied states to skip DOM reads entirely
	let prevTop = null;
	let prevDown = null;
	let prevUp = null;
	let prevScroll = null;

	function normalizeWhitespaceOnce() {
		const cls = root.className;
		const norm = cls.replace(/\s+/g, ' ').trim();
		if (norm !== cls) root.className = norm;
	}
	// Normalize whenever any script changes html.class (evented, batched via rAF)
	let classNormScheduled = false;
	function scheduleNormalize() {
		if (classNormScheduled) return;
		classNormScheduled = true;
		requestAnimationFrame(() => {
			classNormScheduled = false;
			normalizeWhitespaceOnce();
		});
	}
	(new MutationObserver((mutations) => {
		for (let i = 0; i < mutations.length; i++) {
			if (mutations[i].attributeName === 'class') {
				scheduleNormalize();
				break;
			}
		}
	})).observe(root, { attributes: true, attributeFilter: ['class'] });

	function apply() {
		const y = scroller.scrollTop || 0;
		const delta = y - lastY;
		let changed = false;

		// Direction detection with hysteresis
		let nextDir = dir;
		if (Math.abs(delta) > THRESH) {
			nextDir = delta > 0 ? 1 : -1;
		}

		// Top handling: clear direction at absolute top
		if (y === 0) {
			nextDir = 0;
		}

		// Update classes only on change
		if (nextDir !== dir) {
			dir = nextDir;
			const isDown = dir === 1;
			const isUp = dir === -1;
			if (prevDown !== isDown) {
				root.classList.toggle('frl-is-scroll-down', isDown);
				prevDown = isDown;
				changed = true;
			}
			if (prevUp !== isUp) {
				root.classList.toggle('frl-is-scroll-up', isUp);
				prevUp = isUp;
				changed = true;
			}
		}

		// Positional classes
		const isTop = y === 0;
		if (prevTop !== isTop) {
			root.classList.toggle('frl-is-top', isTop);
			prevTop = isTop;
			changed = true;
		}

		// Normalize once if we changed any class (defensive against external writers)
		if (changed) {
			normalizeWhitespaceOnce();
		}

		lastY = y;
		ticking = false;
	}

	// Initial state
	(() => {
		// Initialize prev state before first apply to avoid extra DOM writes
		prevTop = null;
		prevDown = null;
		prevUp = null;
		prevScroll = null;
		apply();
		// Ensure scroll flag starts as false
		const isScroll = false;
		root.classList.toggle('frl-is-scroll', isScroll);
		prevScroll = isScroll;
		normalizeWhitespaceOnce();
	})();

	// ---- DOM-dependent initializations (hero presence, header height var) ----
	let headerEl = null;
	let headerRO = null;
	let headerRaf = 0;

	function setHeaderVarNow() {
		if (!headerEl) return;
		const h = headerEl.offsetHeight || 0;
		root.style.setProperty('--frl-header-height', h + 'px');
	}
	function scheduleHeaderVar() {
		if (headerRaf) return;
		headerRaf = requestAnimationFrame(() => {
			headerRaf = 0;
			setHeaderVarNow();
		});
	}
	function initHeaderVarTracking() {
		headerEl = document.querySelector(SEL_HEADER);
		if (!headerEl) return;
		// Initial set
		setHeaderVarNow();
		// Observe size changes efficiently
		if ('ResizeObserver' in window) {
			headerRO = new ResizeObserver(() => {
				scheduleHeaderVar();
			});
			headerRO.observe(headerEl);
		} else {
			// Fallback: debounced resize
			let rid = 0;
			window.addEventListener('resize', () => {
				if (rid) clearTimeout(rid);
				rid = setTimeout(setHeaderVarNow, 140);
			});
		}
		// Late reflow (fonts, images)
		window.addEventListener('load', scheduleHeaderVar, { once: true });
	}

	function initDomDependent() {
		// Mark presence of hero
		const hasHero = !!document.querySelector(SEL_HERO);
		if (hasHero) {
			root.classList.add('frl-has-hero');
			normalizeWhitespaceOnce();
		}
		// Track header height CSS var
		initHeaderVarTracking();
	}
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initDomDependent, { once: true });
	} else {
		initDomDependent();
	}

	// Passive listener – enqueue rAF work only
	window.addEventListener('scroll', () => {
		// Mark actively scrolling (transient) without DOM reads
		if (prevScroll !== true) {
			root.classList.add('frl-is-scroll');
			prevScroll = true;
			normalizeWhitespaceOnce();
		}

		// Instant frl-is-top when reaching absolute top (no lag)
		const yNow = scroller.scrollTop || 0;
		if (yNow === 0 && prevTop !== true) {
			root.classList.add('frl-is-top');
			prevTop = true;
			normalizeWhitespaceOnce();
			// Also clear direction and scrolling immediately to prevent visual lag
			if (prevDown) {
				root.classList.remove('frl-is-scroll-down');
				prevDown = false;
			}
			if (prevUp) {
				root.classList.remove('frl-is-scroll-up');
				prevUp = false;
			}
			if (prevScroll === true) {
				if (scrollActiveTid) { clearTimeout(scrollActiveTid); scrollActiveTid = 0; }
				root.classList.remove('frl-is-scroll');
				prevScroll = false;
			}
			normalizeWhitespaceOnce();
		}

		// Reset inactivity timer
		if (scrollActiveTid) {
			clearTimeout(scrollActiveTid);
			scrollActiveTid = 0;
		}
		scrollActiveTid = setTimeout(() => {
			if (prevScroll !== false) {
				root.classList.remove('frl-is-scroll');
				prevScroll = false;
				// Optional: normalize once after burst if other scripts inject extra spaces
				const cls = root.className;
				const norm = cls.replace(/\s+/g, ' ').trim();
				if (norm !== cls) root.className = norm;
			}
			scrollActiveTid = 0;
		}, STOP_DELAY);

		if (!ticking) {
			ticking = true;
			requestAnimationFrame(apply);
		}
	}, { passive: true });
})();
