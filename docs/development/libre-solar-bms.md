# Libre Solar BMS development baseline

This is a backend development checkpoint, not a working battery integration.
The Libre Solar plugin has not been generated or registered.
Use the [plugin guide](plugins.md) for the existing MPM extension mechanism.

## Verified baseline

Checks used MPM `a5097f50ee5f7d329d303685ba8a042eaf1d5a65` on Ubuntu 26.04 with PHP 8.5.11, Composer 2.10.3, Docker 29.4.1 and Compose 5.1.3.
Composer platform requirements passed without changing the lockfile.
The existing `ManufacturerTest` passed three tests and six assertions using the guarded `mpm_testing` database on port 53306.
The frontend returned HTTP 200 with 823 bytes.
Backend `/up` and the seeded demo login API returned HTTP 200.
Browser login remains unverified.
These checks used temporary host PHP; they do not verify a clean Docker application startup.
After a development-session disconnect, the frontend, both MySQL services and Redis were found stopped, with no host PHP server or Horizon process running.
The earlier successful HTTP checks describe the pre-disconnect baseline, not current service availability.
Docker inspection confirmed `OOMKilled=true` for the frontend; avoid concurrent builds and quality runs on this environment.

From `src/backend` on the Linux host, with isolated MySQL on port 53306 and Redis on port 6379, run:

```sh
composer check-platform-reqs
php artisan test --filter=ManufacturerTest
composer quality:check
```

Expect successful platform checks and a nonzero test count.
If the bootstrap refuses the database, inspect `.env.testing` and exported database variables; preserve its safety check.
Never point testing at the working demo database.

On a memory-limited host, run the quality components sequentially from `src/backend`, using the same installed tools and project rules:

```sh
php -d memory_limit=768M vendor/bin/php-cs-fixer check --diff --sequential
php -d memory_limit=768M vendor/bin/phpstan analyse --memory-limit=768M --no-progress --debug
php -d memory_limit=768M vendor/bin/rector process --dry-run --debug
```

Run the next command only after the preceding one succeeds.
The debug modes avoid parallel analysis workers; the memory limit is a bound for these checks, not a measured application requirement.
If a command fails, keep its output and distinguish existing findings from changes in the current branch.

## Request and storage path

The global `UserDefaultDatabaseConnectionMiddleware` selects the company before the controller runs.
It matches third-party prefixes through `ThirdPartyApiResolverService`; other user routes normally resolve a JWT.
For ingestion, register only `api/libre-solar-bms/ingest/` with a resolver patterned on `OdysseyPaymentApiResolver`.
`ApiKeyGuard` invokes `ApiKeyProvider`, which hashes the bearer key, looks up an active key, and returns its company.
The database proxy runs the request in that company's tenant connection.
Adding route-level `auth:api-key` without prefix registration is insufficient.
Controllers delegate business logic to services, as demonstrated by Daly BMS credentials.
Tenant business models extend `BaseModel`; each tenant schema operation explicitly uses `Schema::connection('tenant')`.
Plugin registration migrations explicitly use `micro_power_manager`.
Battery reads need normal user authentication and server-side permissions, including plugin activation checks.
Incoming metadata must never create ownership mappings.

## Interface review handoff

The gateway's [API proposal](https://github.com/hubertnshuti/oseas26-libre-solar-bms-monitoring-gateway/blob/66a4fbcf1bdda6dd008de45f8f95455408678f45/docs/api-contract.md) defines single-object summary, event, attributes and status requests, outcomes, pagination and ordering.
It remains proposed until the owners review it.
See [review examples](libre-solar-bms-examples/README.md) for copied normal responses and proposed fault, unknown and disconnected detail states.
They are fixture evidence only.
The list example lacks imbalance, battery temperature and active faults; review its needed fields before changing the shared interface.
The gateway proposal specifies page-based collection browsing with `page` and `per_page` (1–100), an explicit pagination object and stable event ordering.
It distinguishes accepted, duplicate and ignored-stale HTTP 200 outcomes from conflicting IDs (409), unknown provisioned-node mappings (404) and disabled plugins (403).
These are interface proposals, not implemented endpoints in this MPM branch.
Hubert must confirm session promotion, clock limits, error meanings and status reconciliation.
The screen owner must confirm pagination and the list/detail fields.

The merged basic gateway adapter validates and forwards messages but does not implement durable retries, event receipts or freshness status delivery.
Those missing behaviors require the gateway owner before reliable retry and source-freshness integration can be demonstrated.
Fixture-based backend development does not prove simulator-to-MPM integration.

## CI and registration gates

Backend quality, backend tests, frontend quality/build, documentation and generic checks accept PRs targeting `team/bms-monitoring`.
Main push triggers remain unchanged.
Pages deployment and DockerHub/GCP release workflows have not been expanded or enabled for the team branch.
No deployment secrets are needed for the selected checks.
A pushed branch alone does not trigger these PR checks; open the internal PR and inspect its actual runs.

Generate the plugin once from the full Linux clone after the baseline and interface gates are resolved.
Inspect partial changes before any generator retry.
Review the generated plugin ID, provider import, central migration, frontend route and registration component.
The skeleton must be reviewed and merged before the screen owner edits its generated frontend files.

## Runtime limitations and troubleshooting

The final application path must start backend, workers and required infrastructure through documented Compose commands.
It must not require separate host PHP or Horizon terminals.
That path has not been verified here.
The current backend, worker and scheduler entrypoints recursively change ownership of the entire backend bind mount.
Resolve source ownership and initialization before starting those containers on a shared checkout.
Disable repeat demo seeding for existing data and preserve named volumes.
Use only selected services on memory-limited machines; the operator dashboard is unnecessary for this feature.

For local HTTP diagnosis from Linux, with the existing services running:

```sh
curl --noproxy '*' --max-time 10 -I http://127.0.0.1:8001/
curl --noproxy '*' --max-time 10 -I http://127.0.0.1:8000/up
```

Expect HTTP 200; a failure requires checking the existing listener and selected service logs before starting replacements.
Successful curl checks do not establish successful browser login.
For Windows browser timeouts, run in PowerShell:

```powershell
curl.exe --noproxy "*" --connect-timeout 5 --max-time 10 -sS -o NUL -w "HTTP %{http_code}; bytes %{size_download}; time %{time_total}\n" http://127.0.0.1:8001/
Test-NetConnection 127.0.0.1 -Port 8001 -InformationLevel Detailed
```

Compare HTTP and TCP results with Linux checks before changing configuration.
Do not disable firewall or browser protections to bypass an unexplained timeout.
Use the existing local demo credentials in the [development guide](development-environment.md#seeding-the-database) once browser access is working.
Actual UI screenshots and independent clean-setup reproduction remain required.
