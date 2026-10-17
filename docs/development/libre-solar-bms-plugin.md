# Libre Solar BMS plugin

The plugin has central ID `33` (`MpmPlugin::LIBRE_SOLAR_BMS`).
Its backend is `src/backend/app/Plugins/LibreSolarBms/` and frontend is `src/frontend/src/plugins/libre-solar-bms/`.
The battery list is registered at `/libre-solar-bms/overview`; selecting a battery opens its detail and fault history.
The backend accepts summaries, events, metadata and source checks for explicitly provisioned company-owned batteries.

## Registration and activation

The plugin is already generated and registered; do not rerun the generator.
The central registration migration explicitly uses `micro_power_manager`.
The installation command performs no payment, device-control or ownership changes.

With the application database configured and reachable, run from `src/backend`:

```sh
php artisan migrate --force
```

Expect the new central plugin registration migration to succeed.
Inspect a failure before retrying; never reset an existing application database.
Log in as an authorized company administrator and enable LibreSolarBms in plugin settings.
The existing plugin activation flow creates tenant plugin state, adds the registration step and runs `libre-solar-bms:install`.
After the real battery list loads, use `Finish plugin setup` to close that registration step.
Sidebar visibility is conditional on the company's plugin activation.

With the isolated test database and Redis available, run from `src/backend`:

```sh
php artisan test --filter=LibreSolarBmsRegistrationTest
```

Expect a nonzero passing test count covering central registration and tenant activation.
The test bootstrap must select `mpm_testing`; do not bypass that safeguard.

## Operator screens

The [frontend guide](libre-solar-bms-frontend.md) documents the battery list, detail and paginated fault history, including loading, permission, unavailable-data and stale-data states.
The screens use the signed-in user's token and preserve separate source assessments, integration freshness and observation times.
Fixture previews are explicit development tools and do not replace authenticated backend reads.

## Summary ingestion and ownership

The summary backend implements the version-one gateway contract published at `66a4fbcf1bdda6dd008de45f8f95455408678f45`.
The copied schemas and fixtures record source checksums in `src/backend/tests/fixtures/LibreSolarBms/provenance.json`.
Opis JSON Schema 2.6.0 enforces Draft 2020-12 and formats, followed by the gateway's semantic rules.
The other fixture kinds are validated for contract parity; this does not expose a telemetry-history endpoint.

After central and tenant migrations, select the actual company ID deliberately.
An authorized administrator can obtain it from their company's account/API information; never choose the first company silently.
From `src/backend`, with the application database configured:

```sh
php artisan libre-solar-bms:provision COMPANY_ID 53494D0000000001 --label="Demo battery" --adapter=mpm-demo --source=simulator
```

Replace `COMPANY_ID` with that company's positive numeric ID.
Expected: the plugin is enabled and one battery is provisioned for the selected company.
Re-running preserves its label, current state and identity binding.
A conflicting adapter/source requires explicit reconciliation and is rejected.
The install step adds the tenant permission `libre-solar-bms.view` to existing owner/admin roles; other readers must be granted it deliberately.
No payment, customer-device or remote-control record is created.

Obtain an active company key through MPM's existing API-key settings and keep it outside Git.
Existing keys with no expiry retain their lifetime; optional `expires_at` now excludes expired keys from the existing active-key lookup.
This additive central migration requires normal deployment migration before using the updated code.
Revoking a key disables delivery immediately on the next request.

From the repository root, with the backend running and a provisioned node, export `MPM_API_KEY` locally and set the backend URL for your installation:

```sh
export MPM_BACKEND_URL=http://127.0.0.1:8000
curl --fail-with-body "$MPM_BACKEND_URL/api/libre-solar-bms/ingest/summaries" \
  -H "Authorization: Bearer $MPM_API_KEY" \
  -H "Content-Type: application/json" \
  --data-binary @src/backend/tests/fixtures/LibreSolarBms/v1/valid/normal.summary.json
```

Expected: HTTP 200 with `result: accepted` and the input message ID, or `duplicate` on identical replay.
This command sends a constructed fixture, not a fresh simulator observation.
The API derives the tenant from the validated key, rejects unprovisioned nodes (404), disabled plugins (403), bad credentials (401), changed-content identities (409) and invalid payloads (422).
Requests must be JSON objects with a maximum 32 KiB raw body; the global envelope check runs before Laravel decodes input.
Wrong media types return 415 and oversized bodies return 413.
Do not add `company_id` to the payload.

Within a session, older summaries are acknowledged as `ignored_stale` without replacing current state or changing receipt times.
A new session requires a newer observation within 150 seconds of receipt and at most five seconds into the future.
Superseded sessions remain retired; UUID lexical ordering has no meaning.
These clock limits follow the pinned contract.
One current summary row is stored per battery; a small receipt ledger retains identity/fingerprint/outcome evidence for retries and conflicts, not raw telemetry.
There is currently no automatic ledger retention policy; deleting identity evidence would weaken replay/conflict detection.

User reads use JWT authentication and the tenant permission at `GET /api/libre-solar-bms/batteries` and `GET /api/libre-solar-bms/batteries/{id}`.
They return the contract's list/detail shapes, preserve the original summary object and distinguish null values, zero and known-clear fault arrays.
Collection queries accept positive integer `page` and `per_page` (maximum 100).
Invalid pagination returns 422; unknown battery IDs return 404 without searching another tenant.
Without a source-status check, integration freshness remains stale rather than pretending an old summary proves a live battery.

For a read request, set `MPM_USER_TOKEN` locally to the `access_token` returned by MPM's `POST /api/auth/login` for an authorized user.
The company API key is not a user JWT.
With `MPM_BACKEND_URL` set as above, run from the repository root:

```sh
curl --fail-with-body "$MPM_BACKEND_URL/api/libre-solar-bms/batteries?page=1&per_page=25" \
  -H "Authorization: Bearer $MPM_USER_TOKEN" -H "Accept: application/json"
```

Expected: HTTP 200 containing `data` and `pagination`; a provisioned battery with no summary retains null measurements.
Use an `id` returned by this list for the detail and event-history URLs; a caller-supplied node string does not select another company's data.
Inspect a 401 as a user-login/token problem and a 403 as a plugin/permission problem before retrying.
Do not record user tokens in shared output or screenshots.

Run backend verification on the Linux host from `src/backend`, using only the isolated test database and Redis:

```sh
php artisan test --filter=LibreSolarBms
```

Expected: a nonzero test count including actual database writes, separate-company database isolation and shared contract fixtures.
Fixture tests are development evidence; gateway-to-MPM delivery and browser screenshots must be recorded separately.

## Events, metadata and source freshness

The following additional authenticated single-object ingestion routes use the same key, provisioned-node ownership and plugin checks:

| Route                                         | Constructed request fixture  | Stored effect                           |
| --------------------------------------------- | ---------------------------- | --------------------------------------- |
| `POST /api/libre-solar-bms/ingest/events`     | `valid/fault.event.json`     | One event per stable event identity     |
| `POST /api/libre-solar-bms/ingest/attributes` | `valid/demo.attributes.json` | Latest metadata for an existing battery |
| `POST /api/libre-solar-bms/ingest/status`     | `valid/fresh.status.json`    | One independent status/check watermark  |

Fixture paths are relative to `src/backend/tests/fixtures/LibreSolarBms/v1/`.
Use the summary curl example with the matching route and fixture; expect accepted, duplicate or ignored-stale outcomes for matching message IDs.
Same-identity changed content is a 409 conflict; status-revision conflicts use `status_revision_conflict`.
Status must come from the adapter ID chosen during provisioning; another adapter returns `forbidden_adapter` (403).
Rebinding an adapter or resetting its durable counter requires explicit reconciliation; the provisioning command does not guess a safe reset.

## Repeatable local credentials

The provisioning command can create or reuse an existing company key without printing its value.
With the application configured and the selected company ID known, run from `src/backend`:

```sh
php artisan libre-solar-bms:provision COMPANY_ID 53494D0000000001 \
  --label="Demo battery" --adapter=mpm-demo --source=simulator \
  --key-file=storage/app/private/libre-solar-bms-adapter.key
```

Expected: one company-owned battery, one active plugin entry and a private mode-0600 key file.
The same command reuses the same active company key and preserves the battery's label and stored data.
The file path must resolve inside `storage/app/private`; symlinks, public paths and files with broader permissions are rejected.
Existing files are never overwritten, including when their key is expired, revoked or belongs to another company.
Optional `--expires-at=YYYY-MM-DDTHH:MM:SSZ` requires a valid future UTC timestamp when issuing the key; an existing key's expiry is never reset.
If a command fails, inspect its controlled error before retrying rather than deleting a key file to bypass it.

For the [isolated Docker runtime](libre-solar-compose.md), first follow that guide's initialization and Compose environment selection in the same terminal.
Then run the same Artisan command using `docker compose run --rm --no-deps backend` from the repository root.
The key then lives in that project's private storage volume, outside the source checkout.
With the backend running and that same Compose environment selected, load the key into your local shell without displaying it:

```sh
export MPM_API_KEY="$(docker compose exec -T backend cat storage/app/private/libre-solar-bms-adapter.key)"
export MPM_BACKEND_URL=http://127.0.0.1:18000
```

Use these variables with the documented curl example or supply `MPM_API_KEY` and `MPM_BASE_URL` to the gateway adapter's private configuration.
Do not paste the key into a PR, support output, screenshot or tracked environment file.
The central key row stores only the SHA-256 hash.
Tenant provisioning, central key creation and private-file writes span separate resources; a failure during the final database commit requires checking both database states before retrying.
Expected validation failures roll back their writes and remove only a newly created file owned by that attempt.

Events keep observation time separate from backend receipt time and accept previously unseen valid events from retired sessions.
They do not promote sessions, replace metrics or imply a fault began before it was first observed.
Read history at `GET /api/libre-solar-bms/batteries/{id}/events`, with the same JWT permission and pagination rules as the list.
It sorts by observed time descending, then internal event ID descending.
This is page browsing, not an immutable snapshot during concurrent inserts.

Metadata has an independent sequence watermark and cannot establish a live producer session or change company/node/source ownership.
Unsupported SoH/cycle counts, unknown faults and explicit quality reasons remain unchanged in stored payloads.
No null fault is converted to a cleared fault or a fabricated event.

Status revisions are independent of measurement sequences and persist with the current state.
Older revisions are ignored; equal conflicting revisions, regressing check times and checks more than five seconds in the future are rejected.
Retries preserve original `status_checked_at` and `last_source_observed_at`; receipt time never substitutes for check time.
Integration becomes stale when the last accepted original check reaches 150 seconds old, even if a delayed request arrived just now.
A recent source check can remain fresh while a metric summary is 42 seconds old; the 15-second source timeout is the adapter's responsibility, not a metric-row expiry rule.
The UI must age the accepted check independently and label source status separately from integration status.

Backend fixture tests cover these transitions, but the current gateway adapter lacks durable HTTP retry, event receipts and source-status delivery.
Those adapter features and an actual lost-response retry remain integration work requiring compatible gateway behavior.
Do not interpret backend fixture success as proof those gateway features exist.
See [integration validation](libre-solar-bms-handover.md) for recorded simulator delivery evidence and remaining integration limits.
