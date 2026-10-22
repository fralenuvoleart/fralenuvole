<?php
/**
 * WS Form — Email Directive Resolution
 *
 * Resolves @@...@@ directives in WS Form email bodies:
 *   @@include:<name>@@  → contents of assets/templates/emails/<name>.html
 *   @@cta:<action_id>@@ → CTA URL built from FRL_CTA_TEMPLATES (translated + encoded)
 *
 * Split around the translation pass (priority 10):
 *   priority 5  — includes resolved BEFORE translation, so the template's
 *                 {{...}} tokens are translated.
 *   priority 15 — CTA links resolved AFTER translation, so the translation
 *                 cache key stays stable (no per-submission reference ID).
 *
 * @package Fralenuvole
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Registered twice to straddle the priority-10 translation hook: includes before it, CTA links after it.
add_filter( 'wsf_actions_post_submit', 'frl_wsf_resolve_email_includes', 5, 3 );
add_filter( 'wsf_actions_post_submit', 'frl_wsf_resolve_email_cta_links', 15, 3 );

/**
 * Resolve @@include:<name>@@ directives in email bodies.
 *
 * @param mixed $actions Action configs.
 * @param mixed $form    WS Form object.
 * @param mixed $submit  Submit object.
 * @return mixed
 */
function frl_wsf_resolve_email_includes( $actions, $form, $submit ) {
	return frl_wsf_map_email_bodies(
		$actions,
		function ( string $body ) {
			return preg_replace_callback(
				'/@@include:([a-z0-9_\-]+)@@/i',
				function ( $m ) {
					return frl_wsf_load_email_template( $m[1] );
				},
				$body
			) ?? $body;
		}
	);
}

/**
 * Resolve @@cta:<action_id>@@ directives in email bodies.
 *
 * @param mixed $actions Action configs.
 * @param mixed $form    WS Form object.
 * @param mixed $submit  Submit object.
 * @return mixed
 */
function frl_wsf_resolve_email_cta_links( $actions, $form, $submit ) {
	return frl_wsf_map_email_bodies(
		$actions,
		function ( string $body ) use ( $submit ) {
			return preg_replace_callback(
				'/@@cta:([a-z0-9_\-]+)@@/i',
				function ( $m ) use ( $submit ) {
					return frl_wsf_build_cta_url( $m[1], $submit );
				},
				$body
			) ?? $body;
		}
	);
}

/**
 * Apply a body transform to every email action's HTML/text body.
 *
 * @param mixed    $actions   Action configs.
 * @param callable $transform fn( string $body ): string
 * @return mixed
 */
function frl_wsf_map_email_bodies( $actions, callable $transform ) {
	if ( ! is_array( $actions ) ) {
		return $actions;
	}
	foreach ( $actions as $key => $action ) {
		if ( ( $action['id'] ?? '' ) !== 'email' || ! isset( $action['meta'] ) || ! is_array( $action['meta'] ) ) {
			continue;
		}
		foreach ( array( 'action_email_message_html_editor', 'action_email_message_text_editor' ) as $body_key ) {
			$body = $action['meta'][ $body_key ] ?? '';
			if ( ! is_string( $body ) || ! str_contains( $body, '@@' ) ) {
				continue;
			}
			$actions[ $key ]['meta'][ $body_key ] = $transform( $body );
		}
	}
	return $actions;
}

/**
 * Load an email template from assets/templates/emails/, cached by file mtime.
 *
 * @param string $name Template key (whitelisted in FRL_EMAIL_TEMPLATES).
 * @return string Template contents, or '' if invalid/missing.
 */
function frl_wsf_load_email_template( string $name ): string {
	$name = strtolower( $name );
	$file = FRL_EMAIL_TEMPLATES[ $name ] ?? '';
	if ( '' === $file ) {
		return '';
	}
	$path  = FRL_DIR_PATH . 'assets/templates/emails/' . $file;
	$mtime = is_readable( $path ) ? (int) filemtime( $path ) : 0;

	return (string) frl_cache_remember(
		'html',
		'wsf_email_tpl_' . $name . '_' . $mtime,
		function () use ( $path, $name ) {
			if ( ! is_readable( $path ) ) {
				frl_log( 'WSFORM EMAIL: template not readable: {name}', array( 'name' => $name ) );
				return '';
			}
			return (string) file_get_contents( $path );
		},
		WEEK_IN_SECONDS
	);
}

/**
 * Build a CTA URL from FRL_CTA_TEMPLATES, mirroring the client-side pipeline:
 * translate → substitute {reference_id} → {br}→CRLF → encodeURIComponent.
 *
 * @param string $action_id CTA action id (whatsapp|telegram|email).
 * @param mixed  $submit    Submit object.
 * @return string Built URL, or '' if unavailable.
 */
function frl_wsf_build_cta_url( string $action_id, $submit ): string {
	if ( ! defined( 'FRL_CTA_TEMPLATES' ) ) {
		return '';
	}
	$action_id = strtolower( $action_id );

	$env = frl_environment_get_config();
	$cfg = FRL_CTA_TEMPLATES[ $env['prefix'] ?? 'default' ] ?? array();

	$def = null;
	foreach ( $cfg as $a ) {
		if ( ( $a['action_id'] ?? '' ) === $action_id ) {
			$def = $a;
			break;
		}
	}
	if ( ! $def || empty( $def['url'] ) ) {
		return '';
	}

	$lang = frl_get_language( (int) ( $submit->meta['post_id'] ?? 0 ) );

	// Same Polylang string as the CTA module → same translation.
	$template = frl_get_translation( (string) ( $def['template'] ?? '' ), $lang );
	$template = str_replace( '{reference_id}', frl_wsf_get_submitted_reference_id( $submit ), $template );
	// {br} → CRLF AFTER translation (matches call_to_actions.php).
	$template = str_replace( '{br}', "\r\n", $template );

	$url = str_replace( '{template}', frl_encode_uri_component( $template ), $def['url'] );

	if ( ! empty( $def['subject'] ) ) {
		$url = str_replace( '{subject}', frl_encode_uri_component( frl_get_translation( (string) $def['subject'], $lang ) ), $url );
	}

	return $url;
}

/**
 * Read the submitted Reference ID for the form, via the webhook field map.
 *
 * @param mixed $submit Submit object.
 * @return string Reference ID, or '' if not mapped.
 */
function frl_wsf_get_submitted_reference_id( $submit ): string {
	$form_id = (int) ( $submit->form_id ?? 0 );

	foreach ( frl_wsf_get_all_webhook_configs() as $config ) {
		if ( (int) ( $config['form_id'] ?? 0 ) !== $form_id ) {
			continue;
		}
		$field = $config['fields_map']['Reference ID'] ?? '';
		if ( $field && isset( $submit->meta[ $field ] ) ) {
			$value = $submit->meta[ $field ];
			if ( is_array( $value ) && isset( $value['value'] ) ) {
				$value = $value['value'];
			}
			if ( is_scalar( $value ) ) {
				return (string) $value;
			}
		}
	}

	return '';
}

/**
 * PHP equivalent of JavaScript encodeURIComponent().
 *
 * rawurlencode() encodes ! ' ( ) * which encodeURIComponent() leaves literal.
 *
 * @param string $str Input.
 * @return string Encoded output.
 */
function frl_encode_uri_component( string $str ): string {
	return str_replace(
		array( '%21', '%27', '%28', '%29', '%2A' ),
		array( '!', "'", '(', ')', '*' ),
		rawurlencode( $str )
	);
}
