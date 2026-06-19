# Environment Manager: Counterpart Domain Support

## Problem
Kinsta staging uses a completely different domain (`stg-pbservicesge-staging.kinsta.cloud`) that doesn't share a base domain with production (`pbservices.ge`). The current base-domain stripping in `frl_strip_env_prefix()` can't pair them.

## Changes

### 1. config/environment/config-environment.php
Add `counterpart` to PBS production and staging, add Kinsta domain to `FRL_ENV_MAP`:

```php
// Add to FRL_ENV_MAP:
'stg-pbservicesge-staging.kinsta.cloud' => 'FRL_ENV_PBS_STAGING',

// Add 'counterpart' to FRL_ENV_PBS_PRODUCTION:
const FRL_ENV_PBS_PRODUCTION = [
    'extends' => 'FRL_ENV_PBS_TEMPLATE',
    'counterpart' => 'stg-pbservicesge-staging.kinsta.cloud',
];

// Add 'counterpart' to FRL_ENV_PBS_STAGING:
const FRL_ENV_PBS_STAGING = [
    'extends' => 'FRL_ENV_PBS_TEMPLATE',
    'type' => 'staging',
    'counterpart' => 'pbservices.ge',
    'modules' => [
        'subdomain_adapter' => true,
    ],
];
```

### 2. core/environment/class-environment-manager.php
In `add_environment_switcher()`, after the base-domain match fails (line 416), add fallback to check `config['counterpart']`:

Current lines 416-421:
```php
if ($base_domain_current === $base_domain_counterpart) {
    // Found counterpart via base-domain match
    $env_link = "https://" . $domain;
    break;
}
```

Add fallback:
```php
if ($base_domain_current === $base_domain_counterpart) {
    $env_link = "https://" . $domain;
    break;
}
// Fallback: explicit counterpart config (for unrelated domains like Kinsta staging)
if (!empty($config['counterpart']) && $domain === $config['counterpart']) {
    $env_link = "https://" . $domain;
    break;
}
```

## Verification
- `pbservices.ge` ↔ `staging.pbservices.ge` still works via existing base-domain logic
- `pbservices.ge` ↔ `stg-pbservicesge-staging.kinsta.cloud` works via counterpart
- Admin bar switcher shows correct link in both directions
- Zero impact on other FRL_ENV_MAP entries (no counterpart = old behavior)
