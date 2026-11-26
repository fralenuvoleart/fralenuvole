<?php

/**
 * Module Name: PB Services Module
 * Description: Customizations for PB Services (Service form filters, custom post-types, etc.)
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/config-constants-pbs.php';
require_once __DIR__ . '/custom-post-types.php';

add_action(
	'wp_enqueue_scripts',
	'frl_pbs_load_public_scripts',
	FRL_THEMEKIT_STYLE_PRIORITY['modules'],
	0
);

add_filter(
	'wsf_pre_render',
	'frl_pbs_set_monday_field',
	9,
	2
);

/**
 * Add common scripts on frontend requests only (when active language has configured substrings).
 */
function frl_pbs_load_public_scripts() {
	if ( ! frl_is_valid_frontend_page_request() ) {
		return;
	}

	$substrings = PBS_JS_REMOVE_HTML_STRINGS[ frl_get_language() ] ?? '';
	if ( '' === $substrings ) {
		return;
	}

	$assets = array( 'pbs-public-js' => 'modules/pbs/assets/js/public.js' );
	frl_enqueue_scripts( $assets, 'pbs_public' );

	$handle = FRL_PREFIX . '-pbs-public';
	if ( ! wp_script_is( $handle, 'enqueued' ) ) {
		return;
	}

	$data = array(
		'selector'   => PBS_JS_REMOVE_HTML_SELECTOR,
		'substrings' => $substrings,
	);
	wp_localize_script( $handle, 'pbsSettings', $data );
}


/** Set the Service-Type field default value. */
function frl_pbs_set_monday_field( $form, $preview ) {
	/** @disregard P1010 Undefined type */
	$fields = wsf_form_get_fields( $form );
	if ( ! is_iterable( $fields ) ) {
		return $form;
	}

	foreach ( $fields as $object ) {
		if ( ! is_object( $object ) || ! isset( $object->id ) ) {
			continue;
		}
		/** @disregard P1010 Undefined type */
		$field = wsf_field_get_object( $form, $object->id );

		if ( ! is_object( $field ) || ! isset( $field->label, $field->meta ) || ! is_object( $field->meta ) ) {
			continue;
		}

		if ( 'Service-Type' === $field->label && empty( $field->meta->default_value ) ) {
			$field->meta->default_value = 'Webpage';
		}
	}

	return $form;
}
