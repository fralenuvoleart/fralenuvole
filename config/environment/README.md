ENVIRONMENT CONFIGURATION
=========================

MERGE STACK  (bottom wins)

  FRL_ENV_DEFAULT       baseline, always applied
  type partial          staging or production adjustments, always applied
  extends               brand template, optional
  instance              the constant referenced in FRL_ENV_MAP

One level only. Templates cannot extend templates.


DEFAULT VALUES  (FRL_ENV_DEFAULT)
----------------------------------
type                    production

plugins active          litespeed-cache, docket-cache
plugins inactive        query-monitor, better-search-replace

modules
  wsform                on
  thirdparty            on
  acf                   off
  pbs                   off
  pbproperty            off
  pbnova                off
  frl                   off

wp_options
  blog_public           1  (indexed)

plugin_options
  wsform_webhook        off
  disable_themekit      on
  schema_organization   on
  schema_service        on
  schema_person         off
  schema_portfolio      off
  header_html           empty
  header_html_php       off
  footer_html           file
  footer_html_php       on
  debug                 off
  error_reporting_email         on
  error_reporting_notice        on
  error_reporting_warning       on
  error_reporting_deprecated    on


STAGING OVERRIDES  (FRL_ENV_DEFAULT_STAGING)
---------------------------------------------
plugins active          query-monitor, better-search-replace
plugins inactive        litespeed-cache, docket-cache

  blog_public           0  (hidden)
  disable_themekit      off
  debug                 on
  error_reporting_email off


MASTER TEMPLATE  (FRL_ENV_MASTER_TEMPLATE)
-------------------------------------------
For new sites with no dedicated brand template.

plugins active          query-monitor

  disable_themekit      off
  debug                 on


ADDING A NEW DOMAIN
--------------------
1. Define a brand template in config-environment.php if the brand is new
2. Define an instance constant with 'extends' and only the delta
3. Add the domain to FRL_ENV_MAP
