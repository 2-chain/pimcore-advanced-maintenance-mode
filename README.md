# Pimcore Advanced Maintenance Mode

[![Latest Stable Version](https://img.shields.io/packagist/v/2chain/pimcore-advanced-maintenance-mode.svg)](https://packagist.org/packages/2chain/pimcore-advanced-maintenance-mode)
[![CI](https://github.com/2-chain/pimcore-advanced-maintenance-mode/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/2-chain/pimcore-advanced-maintenance-mode/actions/workflows/ci.yml)
[![Coverage](https://img.shields.io/codecov/c/github/2-chain/pimcore-advanced-maintenance-mode/main.svg)](https://codecov.io/gh/2-chain/pimcore-advanced-maintenance-mode)
[![License](https://img.shields.io/packagist/l/2chain/pimcore-advanced-maintenance-mode.svg)](LICENSE.md)

Pimcore bundle that extends Pimcore's built-in maintenance mode with **rule-based exemptions** (HTTP routes, CLI commands, IP/CIDR, PHP attributes), ergonomic improvements (`Retry-After` header, activation reason, admin-session bypass, debug command), and a full set of operational features: scheduled windows, TTL safety net, pre-announce countdown banner, post-maintenance health checks, selective scope, and a Pimcore admin dashboard.

|||
|-|--|
| Package | `2chain/pimcore-advanced-maintenance-mode` |
| PHP namespace | `TwoChain\PimcoreAdvancedMaintenanceModeBundle\` |
| Bundle class | `TwoChain\PimcoreAdvancedMaintenanceModeBundle\PimcoreAdvancedMaintenanceModeBundle` |
| Pimcore | `^11.0 \|\| ^12.0` |
| Symfony | `^6.4 \|\| ^7.0` |
| License | [GPL-3.0-or-later](LICENSE.md) |

## Features

| Feature | Summary | Doc |
|---------|---------|-----|
| **Core exemptions** | Rule-based bypass for HTTP routes, CLI commands, and IPs. PHP attribute, YAML, and env-var sources merge additively. `Retry-After` + reason headers. Admin-session bypass. `debug` command. | [docs/features/core-exemptions.md](docs/features/core-exemptions.md) |
| **Scheduled windows** | One-time and recurring cron-based windows enforced by a Pimcore maintenance task. Overlap detection, dry-run, queue on manual block. Public status endpoint. Self-test `doctor` command. | [docs/features/scheduled-windows.md](docs/features/scheduled-windows.md) |
| **Heartbeat / TTL** | Attach a TTL to any manual activation — auto-deactivates on expiry. `heartbeat` command for deploy scripts. Pre-expiry WARNING log. | [docs/features/heartbeat-ttl.md](docs/features/heartbeat-ttl.md) |
| **Pre-announce banner** | Live-countdown banner on every HTML page before maintenance starts. Colour-escalating, dismissible, zero template changes. Email and webhook notifications at every lifecycle event. | [docs/features/pre-announce-banner.md](docs/features/pre-announce-banner.md) |
| **Admin dashboard** | Pimcore admin panel (Tools menu): upcoming schedule grid, execution history, status strip, exemptions panel, create/clone/delete/end-now, start-page portlet. | [docs/features/admin-dashboard.md](docs/features/admin-dashboard.md) |
| **Health checks** | Post-deactivation checks (HTTP GET, DB ping, console command). Retries up to 3×; re-enters maintenance automatically on exhaustion. | [docs/features/health-checks.md](docs/features/health-checks.md) |
| **Selective maintenance** | Scope maintenance to URL path prefixes and/or Pimcore site IDs. Out-of-scope requests pass through normally. | [docs/features/selective-maintenance.md](docs/features/selective-maintenance.md) |

## Why

Out of the box, Pimcore's maintenance mode is all-or-nothing: every CLI command needs `--ignore-maintenance-mode`, every HTTP request gets a 503 unless it comes from `127.0.0.1` or the activator's session. Cron jobs, partner webhooks, and health checks should keep running. This bundle lets you declare those exceptions once and forget about them.

## Install

```bash
composer require 2chain/pimcore-advanced-maintenance-mode
```

Enable the bundle in your host project (`config/bundles.php`):

```php
return [
    // …
    TwoChain\PimcoreAdvancedMaintenanceModeBundle\PimcoreAdvancedMaintenanceModeBundle::class => ['all' => true],
];
```

No database migration. No Pimcore admin permissions. No assets to install.

## Configure

`config/packages/two_chain_advanced_maintenance_mode.yaml`:

```yaml
two_chain_advanced_maintenance_mode:
    bypass_authenticated_admins: true
    default_retry_after: 300

    exemptions:
        commands:
            - 'messenger:consume*'
            - { pattern: 'doctrine:migrations:*', id: 'doctrine-migrations' }
        routes:
            - { path: '/health' }
            - { path: '/api/webhooks/*', methods: [POST] }
            - { route: 'app_api_orders_list' }
        ips:
            - '10.0.0.0/8'
        messenger_workers: true   # coarse switch — exempts every messenger:* command
```

You can also exempt routes / commands by attribute:

```php
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Attribute\ExemptFromMaintenance;
use Symfony\Component\Routing\Attribute\Route;

#[ExemptFromMaintenance(id: 'order-webhook')]
final class OrderWebhookController
{
    #[Route('/api/webhooks/orders', methods: ['POST'])]
    public function __invoke(): Response { /* … */ }
}
```

Or via environment variables for incident-response overrides:

```bash
ADVANCED_MAINTENANCE_EXEMPT_COMMANDS="messenger:*,my:critical:cmd"
ADVANCED_MAINTENANCE_EXEMPT_ROUTES="/health,/api/webhooks/*"
ADVANCED_MAINTENANCE_EXEMPT_IPS="10.0.0.0/8"
```

All three sources merge additively. Env can only add rules, never remove a YAML rule.

## Use

Enable maintenance mode with a reason:

```bash
bin/console pimcore:advanced-maintenance:enable --reason="DB migration v3.5" --retry-after=600
```

Disable:

```bash
bin/console pimcore:advanced-maintenance:disable
```

Inspect / simulate (read-only):

```bash
bin/console pimcore:advanced-maintenance:debug
bin/console pimcore:advanced-maintenance:debug --route=/api/webhooks/orders --method=POST --ip=10.5.4.3
bin/console pimcore:advanced-maintenance:debug --command=messenger:consume
```

## Built-in defaults

Three rule groups are baked in (toggle via `builtin_exemptions`):

| Group | What | Why |
|---|---|---|
| `bundle_own_commands` | `pimcore:advanced-maintenance:*` | The disable command must work while mode is on. |
| `symfony_info_commands` | `help`, `list`, `_complete`, `completion`, `about` | Read-only introspection — keep the CLI usable. |
| `loopback` | `127.0.0.1` + `::1` | Matches Pimcore's hardcoded IPv4 loopback, adds IPv6. |

## Observability

When an HTTP request bypasses maintenance mode:

```
HTTP/1.1 200 OK
X-Maintenance-Bypass: order-webhook
X-Maintenance-Reason: DB migration v3.5
```

When the 503 is served:

```
HTTP/1.1 503 Service Unavailable
Retry-After: 600
X-Maintenance-Reason: DB migration v3.5
```

When a CLI command bypasses:

```
[maintenance bypass] Rule "messenger-workers" (builtin) — command runs under exemption.
[maintenance] Reason: DB migration v3.5
```

## How it works

The bundle registers a high-priority `kernel.request` listener (priority 127, between `SessionListener` at 128 and Pimcore's `MaintenancePageListener` at 126). When maintenance mode is active and an exemption rule matches, the listener sets a request attribute `_advanced_maintenance_match`.

A second piece — `RequestAwareMaintenanceModeHelper`, a decorator over Pimcore's `MaintenanceModeHelperInterface` — checks for that attribute on every `isActive()` call. If present, it returns `false`, which causes Pimcore's own `MaintenancePageListener` to skip the 503. Downstream listeners (`RouterListener`, the firewall) then run as if maintenance mode were off, and the request reaches the controller normally.

A `kernel.response` listener attaches the `X-Maintenance-Bypass` / `X-Maintenance-Reason` / `Retry-After` headers based on the request attributes.

For CLI: `ConsoleExemptionListener` at priority 100 flips the `--ignore-maintenance-mode` input option when a command matches an exemption rule. Pimcore's existing CLI gate at priority 0 then sees the flag and passes the command through.

## Limitations

- CLI exemptions apply only when running through `bin/console`. Scripts that bootstrap the kernel via `Bootstrap::kernel()` outside Pimcore's `Console\Application` cannot be intercepted from a bundle (Pimcore's gate runs before our listener loads).
- Activation reason is stored in Pimcore's `TmpStore` and is not persisted across `pimcore:maintenance-mode` (the native command) — use this bundle's `pimcore:advanced-maintenance:enable` to set it.
- When the host runs the native `pimcore:maintenance-mode` command, an informational notice is printed pointing operators at `pimcore:advanced-maintenance:enable` (where reason / retry-after are available).

## Twig integration

For custom maintenance templates:

```twig
{% if maintenance_reason() %}
    <p>Reason: {{ maintenance_reason() }}</p>
{% endif %}
{% if maintenance_retry_after() %}
    <p>We'll be back in approximately {{ maintenance_retry_after() }} seconds.</p>
{% endif %}
```

## Further reading

Each feature has a dedicated doc under [`docs/features/`](docs/features/) with full configuration reference, command examples, and integration notes.
