<?php
// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/config-defaults.php';

// --- Environment Map ---
const FRL_ENV_MAP = array(
	'pbservices.ge'                         => 'FRL_ENV_PBS_PRODUCTION',
	'ru.pbservices.ge'                      => 'FRL_ENV_PBS_RU_SUBDOMAIN',
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
	'webhook_config' => 'pbs',
	'modules'        => array(
		'pbs'               => true,
		'subdomain_adapter' => true,
	),
	'plugin_options' => array(
		'wsform_webhook' => true,
	),
);

const FRL_ENV_PBS_PRODUCTION = array(
	'extends'     => 'FRL_ENV_PBS_TEMPLATE',
	'counterpart' => 'stg-pbservicesge-staging.kinsta.cloud',
);

/** RU Subdomain - PBS replica on Russian server */
const FRL_ENV_PBS_RU_SUBDOMAIN = array(
	'extends'    => 'FRL_ENV_PBS_TEMPLATE',
	'prefix'     => 'pbs_ru',
	'wp_options' => array(
		'blog_public' => 0,
	),
	'modules'    => array(
		'subdomain_adapter' => true,
	),
);

const FRL_ENV_PBS_STAGING = array(
	'extends'     => 'FRL_ENV_PBS_TEMPLATE',
	'type'        => 'staging',
	'counterpart' => 'pbservices.ge',
	'modules'     => array(
		'subdomain_adapter' => true,
	),
);

// --- PBP ---
const FRL_ENV_PBP_TEMPLATE = array(
	'prefix'         => 'pbp',
	'webhook_config' => 'pbp',
	'modules'        => array(
		'pbproperty' => true,
	),
	'plugin_options' => array(
		'wsform_webhook'           => true,
		'header_html'              => 'file',
		'header_html_php'          => true,
		'schema_organization_name' => 'PB Property Georgia',
		'schema_organization_url'  => 'https://pbproperty.ge/',
	),
);

const FRL_ENV_PBP_PRODUCTION = array(
	'extends'     => 'FRL_ENV_PBP_TEMPLATE',
	'counterpart' => 'stg-pbproperty-staging.kinsta.cloud',
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
		'schema_organization_name' => 'PB Nova',
		'schema_organization_url'  => 'https://pbnova.com/',
		'schema_founder_name'      => 'Francesco Castronovo',
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
	'modules'        => array(
		'frl' => true,
	),
	'plugin_options' => array(
		'schema_organization_name' => 'Fralenuvole',
		'schema_organization_url'  => 'https://fralenuvole.art/',
		'schema_founder_name'      => 'Francesco Castronovo',
	),
);
