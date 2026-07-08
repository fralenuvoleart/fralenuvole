<?php
/**
 * Translation System Admin Access
 *
 * Grants access to the Polylang String Translations screen
 * (Languages > Translations) for non-administrator users
 * who have the edit_pages capability (Editors and above).
 *
 * In Polylang Free, the Strings Translations page requires
 * manage_options (administrators only). This snippet registers
 * the same mlang_strings page with edit_pages so Editors can
 * use it without full admin rights.
 *
 * Future: WPML support can be added here using
 * wpml_manage_string_translation capability.
 *
 * @package Fralenuvole
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register an alternative Polylang Strings Translations menu
 * for users who have edit_pages but not manage_options.
 */
add_action(
	'admin_menu',
	function () {
		// Bail if Polylang isn't active — this file is Polylang-only.
		if ( ! frl_is_polylang_active() ) {
			return;
		}

		// Only users with edit_pages (Editors and up) get access.
		if ( ! current_user_can( 'edit_pages' ) ) {
			return;
		}

		add_menu_page(
			__( 'Strings translations', 'polylang' ),
			__( 'Languages', 'polylang' ),
			'edit_pages',
			'mlang_strings',
			array( PLL(), 'languages_page' ),
			'dashicons-translation'
		);
	}
);
