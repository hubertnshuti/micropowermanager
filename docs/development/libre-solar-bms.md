# Libre Solar BMS integration

The Libre Solar BMS plugin connects normalized battery summaries, fault events, metadata and source checks to MPM's company-scoped storage and operator screens.
It stores the latest battery state and useful events rather than the one-second telemetry stream.
The current integration uses a simulator; physical hardware commissioning and battery control are outside its scope.

## Development guides

- [Plugin registration, provisioning and API](libre-solar-bms-plugin.md)
- [Battery list, detail and fault-history screens](libre-solar-bms-frontend.md)
- [Isolated Docker development runtime](libre-solar-compose.md)
- [Integration validation and reproduction](libre-solar-bms-handover.md)
- [API response examples](libre-solar-bms-examples/README.md)

The [plugin guide](plugins.md) describes MPM's general extension mechanism.
LibreSolarBms is already registered with central plugin ID `33`; do not regenerate its skeleton.

## Authentication and storage

The global `UserDefaultDatabaseConnectionMiddleware` resolves the company before the controller runs.
`ThirdPartyApiResolverService` registers `api/libre-solar-bms/ingest/` for company API-key resolution.
`ApiKeyGuard` validates the hashed bearer key and selects the authenticated company's tenant database.
Route-level `auth:api-key` alone does not perform that prefix registration.

Battery reads use MPM user JWT authentication, an enabled plugin and the tenant permission `libre-solar-bms.view`.
Nodes must be provisioned explicitly for a company before ingestion.
Incoming metadata cannot create or change ownership mappings.
Tenant models extend `BaseModel`, and tenant migrations explicitly use `Schema::connection('tenant')`.
Central plugin registration and API-key migrations use `micro_power_manager`.

## Verification

Run backend tests only against the isolated `mpm_testing` database on port 53306, with Redis available.
From `src/backend`:

```sh
composer check-platform-reqs
php artisan test --filter=LibreSolarBms
composer quality:check
```

The test bootstrap rejects an incorrect database; preserve that safeguard.
The frontend guide documents the focused battery tests, formatting, locale checks and build commands.
The integration validation guide records historical test results with their source revisions and describes how to reproduce simulator delivery.

On a memory-constrained host, run the existing backend quality tools sequentially:

```sh
php -d memory_limit=768M vendor/bin/php-cs-fixer check --diff --sequential
php -d memory_limit=768M vendor/bin/phpstan analyse --memory-limit=768M --no-progress --debug
php -d memory_limit=768M vendor/bin/rector process --dry-run --debug
```

The debug modes avoid parallel analysis workers.
Run each command after the preceding one succeeds; the memory limit bounds these checks and is not a measured application requirement.
Backend, frontend, documentation and generic CI checks accept PRs targeting both `main` and `team/bms-monitoring`.

## Integration limits

The API preserves unknown values, quality reasons, observation times and separate receipt times.
Source status and integration freshness require accepted source checks; an old metric summary cannot establish that a battery is online.
The gateway adapter described in the pinned integration evidence forwards summaries, events and metadata but does not yet provide durable HTTP retries, stored-event receipt publication or source-status delivery.
The linked guides document these limits and the additional verification required for a complete deployment.
