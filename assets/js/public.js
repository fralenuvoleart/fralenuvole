/**
 * Fralenuvole – public.js
 *
 * Patches WordPress core navigation submenu aria-expanded on mobile.
 *
 * Two problems exist in core's Interactivity API:
 * 1. When the hamburger overlay opens, core sets aria-expanded="true" on ALL
 *    submenu toggles even though submenus are hidden.
 * 2. When a submenu toggle is clicked, core does NOT toggle aria-expanded at
 *    all — the data-wp-bind binding is broken/non-reactive on mobile.
 *
 * Solution:
 * - A MutationObserver catches case 1 and reverts false positives.
 * - A delegated click handler catches case 2 and syncs aria-expanded to the
 *   actual submenu visibility after core's handlers run.
 */

(() => {
	// ---- MutationObserver: prevent hamburger-open from setting all toggles to true ----
	let correcting = false;

	const observer = new MutationObserver((mutations) => {
		if (correcting) return;

		for (const mutation of mutations) {
			const toggle = mutation.target;
			if (!toggle.matches('.wp-block-navigation-submenu__toggle')) continue;
			if (toggle.getAttribute('aria-expanded') !== 'true') continue;

			const submenu = toggle.nextElementSibling;
			if (!submenu) continue;

			const isOpen =
				submenu.classList.contains('wp-block-navigation-submenu__visible') ||
				submenu.getAttribute('aria-hidden') === 'false';

			if (!isOpen) {
				correcting = true;
				toggle.setAttribute('aria-expanded', 'false');
				correcting = false;
			}
		}
	});

	observer.observe(document.body, {
		attributes: true,
		attributeFilter: ['aria-expanded'],
		subtree: true,
	});

	// ---- Click handler: toggle aria-expanded when submenu toggle is clicked ----
	// Core does not modify the DOM at all when the toggle is clicked on mobile
	// (the Interactivity API binding is broken), so we flip the attribute directly.
	// Only one submenu is open at a time — clicking a toggle closes all others.
	// The correcting flag is held true via setTimeout(0) so the MutationObserver
	// (which fires as a microtask after this handler) sees it and skips correction.
	document.addEventListener('click', (e) => {
		const toggle = e.target.closest('.wp-block-navigation-submenu__toggle');
		if (!toggle) return;

		const current = toggle.getAttribute('aria-expanded') === 'true';
		correcting = true;

		// Close all other submenu toggles
		document.querySelectorAll('.wp-block-navigation-submenu__toggle').forEach((t) => {
			if (t !== toggle) {
				t.setAttribute('aria-expanded', 'false');
			}
		});

		toggle.setAttribute('aria-expanded', String(!current));
		setTimeout(() => { correcting = false; }, 0);
	});
})();