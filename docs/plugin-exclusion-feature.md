# Plugin Exclusion Feature

## Overview

Prevents specified plugins from loading without deactivating them. Uses MU plugin to filter `active_plugins` before regular plugins load.

## How It Works

The MU loader (`assets/mu/frl-mu-plugin.php`) runs at `muplugins_loaded` hook and adds a `pre_option_active_plugins` filter to remove excluded plugins.

## Conditions

| Exclusion Type | When Applied | Applies To |
|----------------|---------------|------------|
| **Frontend** | Frontend context (HTML + AJAX) | All users |
| **Capability** | Non-frontend context (admin) | Users without required cap |

## Behavior

| Context | Has Cap? | Result |
|----------|----------|--------|
| Frontend | Any | BLOCKED |
| Admin | No | BLOCKED |
| Admin | Yes | LOADS |
| REST/MCP/Cron | Any | LOADS |

## File

- `assets/mu/frl-mu-plugin.php` - MU loader implementation