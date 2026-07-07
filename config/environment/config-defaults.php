<?php
// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// --- System Constants ---
const FRL_ENV_PREFIX         = FRL_PREFIX . '_';
const FRL_ENV_CACHE_GROUP    = 'environment';
const FRL_ENV_CACHE_KEY      = 'state';
const FRL_IGNORE_PLUGINS_KEY = 'ignore_plugins';
const FRL_IGNORE_OPTIONS_KEY = 'ignore_options';
const FRL_ENV_FILES_PATH     = 'config/environment/env-snippets/';

// Clear all website transients on environment migration on admin visits.
// Default true (safe with per-host throttle and admin-only guard).
const FRL_ENV_CLEAR_WEBSITE_TRANSIENTS = true;

// Subdomain prefixes that identify a staging environment.
// Used for sibling-domain detection (switcher button and secondary links filter).
// www. is NOT listed here — it is a canonical alias convention, handled separately.
const FRL_ENV_STAGING_PREFIXES = array( 'stg-', 'staging.', 'stage', 'dev.', 'test' );

// --- Base Default Configuration ---
/** Universal baseline applied to every site. Override per brand via templates. */
const FRL_ENV_DEFAULT = array(
	'prefix'         => 'default',         // Requires override — display label only
	'type'           => 'production',        // Base type
	'webhook_config' => false,     // Requires override — false = no webhook lookup
	'plugins'        => array(
		'active'   => array(
			'litespeed-cache/litespeed-cache.php',
			'docket-cache/docket-cache.php',
		),
		'inactive' => array(
			'query-monitor/query-monitor.php',
			'better-search-replace/better-search-replace.php',
		),
	),
	'modules'        => array(
		'wsform'            => true,
		'thirdparty'        => true,
		'subdomain_adapter' => false,
		'acf'               => false,
		'acf-migration'     => false,
		'pbnova'            => false,
		'pbs'               => false,
		'pbproperty'        => false,
		'frl'               => false,
	),
	'wp_options'     => array(
		'blog_public' => 1,
	),
	'plugin_options' => array(
		'wsform_webhook'             => false,
		'header_html'                => '',
		'header_html_php'            => false,
		'footer_html'                => 'file',
		'footer_html_php'            => true,
		'debug'                      => false,
		'error_reporting_email'      => true,
		'error_reporting_notice'     => true,
		'error_reporting_warning'    => true,
		'error_reporting_deprecated' => true,
		'schema_organization_name'   => 'PB Services Georgia',
		'schema_organization_url'    => 'https://pbservices.ge/',
		'schema_founder_name'        => 'Rati (Iese) Abashmadze',
	),
);

/** Production Diffs from FRL_ENV_DEFAULT */
const FRL_ENV_DEFAULT_PRODUCTION = array(
	// FRL_ENV_DEFAULT represents default production
);

/** Staging Diffs from FRL_ENV_DEFAULT */
const FRL_ENV_DEFAULT_STAGING = array(
	'type'           => 'staging',
	'plugins'        => array(
		'active'   => array(
			'query-monitor/query-monitor.php',
			'better-search-replace/better-search-replace.php',
		),
		'inactive' => array(
			'litespeed-cache/litespeed-cache.php',
			'docket-cache/docket-cache.php',
		),
	),
	'wp_options'     => array(
		'blog_public' => 0,
	),
	'plugin_options' => array(
		'footer_html'           => 'file',
		'debug'                 => true,
		'error_reporting_email' => false,
	),
);

// --- Generic Template ---
/** Base template for new websites with no dedicated brand template yet. */
const FRL_ENV_MASTER_TEMPLATE = array(
	'prefix'         => 'master',
	'plugins'        => array(
		'active' => array(
			'query-monitor/query-monitor.php',
		),
	),
	'plugin_options' => array(
		'debug' => true,
	),
);
