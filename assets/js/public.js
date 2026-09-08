/**
 * Fralenuvole – public.js
 *
 * Patches WordPress core navigation submenu aria-expanded on mobile.
 *
 * Core's Interactivity API (data-wp-bind--aria-expanded) has two bugs:
 * 1. Hamburger-open sets aria-expanded="true" on ALL toggles, even hidden ones.
 * 2. Clicking a toggle does not flip aria-expanded at all.
 *
 * A MutationObserver corrects case 1 by reverting false positives (submenu
 * hidden but aria-expanded="true"). A delegated click handler handles case 2
 * by directly flipping the attribute. Only one submenu is open at a time
 * (accordion behaviour).
 */

(() => {
	let skipObserver = false;

	const observer = new MutationObserver((mutations) => {
		if (skipObserver) { skipObserver = false; return; }

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
				toggle.setAttribute('aria-expanded', 'false');
			}
		}
	});

	observer.observe(document.body, {
		attributes: true,
		attributeFilter: ['aria-expanded'],
		subtree: true,
	});

	// Click handler: directly flips aria-expanded on the clicked toggle.
	// Core does not modify the DOM at all on mobile toggle clicks (the
	// Interactivity API binding is broken), so there is nothing to defer
	// for — we flip the attribute ourselves. skipObserver prevents the
	// MutationObserver from reverting our writes.
	document.addEventListener('click', (e) => {
		const toggle = e.target.closest('.wp-block-navigation-submenu__toggle');
		if (!toggle) return;

		const current = toggle.getAttribute('aria-expanded') === 'true';
		skipObserver = true;

		// Close all other open toggles — only touch those already "true"
		document.querySelectorAll('.wp-block-navigation-submenu__toggle[aria-expanded="true"]').forEach((t) => {
			if (t !== toggle) {
				t.setAttribute('aria-expanded', 'false');
			}
		});

		toggle.setAttribute('aria-expanded', String(!current));
	});
})();