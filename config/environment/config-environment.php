<?php
// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/config-defaults.php';

// --- Environment Map ---
const FRL_ENV_MAP = array(
	'pbservices.ge'                         => 'FRL_ENV_PBS_PRODUCTION',
	'pbproperty.ge'                         => 'FRL_ENV_PBP_PRODUCTION',
	'pbnova.com'                            => 'FRL_ENV_PBNOVA_PRODUCTION',
	'fralenuvole.art'                       => 'FRL_ENV_FRALENUVOLE_PRODUCTION',
	'master.fralenuvole.art'                => 'FRL_ENV_MASTER_TEMPLATE',
	'stg-pbservicesge-staging.kinsta.cloud' => 'FRL_ENV_PBS_STAGING',
	'stg-pbproperty-staging.kinsta.cloud'   => 'FRL_ENV_PBP_STAGING',
	'stg-pbnovacom-staging.kinsta.cloud'    => 'FRL_ENV_PBNOVA_STAGING',
);

// --- PBS ---
const FRL_ENV_PBS_TEMPLATE = array(
	'prefix'         => 'pbs',
	'modules'        => array(
		'pbs'             => true,
		'call_to_actions' => true,
	),
	'plugin_options' => array(
		'schema_founder_name' => 'Rati (Iese) Abashmadze',
		'schema_founder_url'  => 'https://pbservices.ge/about/rati-abashmadze/',
		'schema_org_sameas'   => '
			https://www.facebook.com/PBservices.georgia
			https://www.instagram.com/pbservices.ge
			https://www.linkedin.com/company/pbservices/
		',
	),
);

const FRL_ENV_PBS_PRODUCTION = array(
	'extends'        => 'FRL_ENV_PBS_TEMPLATE',
	'counterpart'    => 'stg-pbservicesge-staging.kinsta.cloud',
	'plugin_options' => array(
		'wsform_webhook'  => true,
		'wsform_use_cron' => true,
		'cta_webhook'     => true,
		'cta_use_cron'    => false,
	),
);

const FRL_ENV_PBS_STAGING = array(
	'extends'        => 'FRL_ENV_PBS_TEMPLATE',
	'type'           => 'staging',
	'counterpart'    => 'pbservices.ge',
	'plugin_options' => array(
		'wsform_webhook'  => true,
		'wsform_use_cron' => false,
		'cta_webhook'     => true,
		'cta_use_cron'    => false,
	),
);

// --- PBP ---
const FRL_ENV_PBP_TEMPLATE = array(
	'prefix'         => 'pbp',
	'modules'        => array(
		'pbproperty'      => true,
		'call_to_actions' => true,
	),
	'plugin_options' => array(
		'header_html'         => 'file',
		'header_html_php'     => true,
		'schema_founder_name' => 'Sopho Chaduneli',
		'schema_founder_url'  => 'https://pbproperty.ge/',

	),
);

const FRL_ENV_PBP_PRODUCTION = array(
	'extends'        => 'FRL_ENV_PBP_TEMPLATE',
	'counterpart'    => 'stg-pbproperty-staging.kinsta.cloud',
	'plugin_options' => array(
		'wsform_webhook'  => true,
		'wsform_use_cron' => true,
		'cta_webhook'     => true,
		'cta_use_cron'    => false,
	),
);

const FRL_ENV_PBP_STAGING = array(
	'extends'     => 'FRL_ENV_PBP_TEMPLATE',
	'type'        => 'staging',
	'counterpart' => 'pbproperty.ge',
);

// --- PB Nova ---
const FRL_ENV_PBNOVA_TEMPLATE = array(
	'prefix'         => 'pbnova',
	'modules'        => array(
		'pbnova' => true,
	),
	'wp_options'     => array(
		'blog_public' => 0,
	),
	'plugin_options' => array(
		'schema_founder_name' => 'Francesco Castronovo',
		'schema_founder_url'  => 'https://pbnova.com/',
	),
);

const FRL_ENV_PBNOVA_PRODUCTION = array(
	'extends'     => 'FRL_ENV_PBNOVA_TEMPLATE',
	'type'        => 'staging', // Temporary: treated as staging until site is ready for production
	'counterpart' => 'stg-pbnovacom-staging.kinsta.cloud',
);

const FRL_ENV_PBNOVA_STAGING = array(
	'extends'     => 'FRL_ENV_PBNOVA_TEMPLATE',
	'type'        => 'staging',
	'counterpart' => 'pbnova.com',
);

// --- Fralenuvole ---
const FRL_ENV_FRALENUVOLE_PRODUCTION = array(
	'extends'        => 'FRL_ENV_MASTER_TEMPLATE',
	'prefix'         => 'frl',
	'plugins'        => array(
		'active' => array(
			'query-monitor/query-monitor.php',
		),
	),
	'modules'        => array(
		'frl' => true,
	),
	'plugin_options' => array(
		'schema_founder_name' => 'Francesco Castronovo',
		'schema_founder_url'  => 'https://fralenuvole.art/about/',
	),
);

/**
 * Adding a new Domain
 * 1. Add the domain to FRL_ENV_MAP
 * 2. Define a new domain template
 * 3. Add an instance constant with 'extends' and the diffs
 */
