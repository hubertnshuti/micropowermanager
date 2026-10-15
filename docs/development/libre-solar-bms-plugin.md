# Libre Solar BMS plugin

The generated plugin has central ID `33` (`MpmPlugin::LIBRE_SOLAR_BMS`).
Its backend is `src/backend/app/Plugins/LibreSolarBms/` and frontend is `src/frontend/src/plugins/libre-solar-bms/`.
The generated placeholder is registered at `/libre-solar-bms/overview`.
It is not a battery screen or evidence of a working ingestion API.

## Registration and activation

From the complete source clone, the plugin generator was run once on the Linux host.
Do not run it again for this plugin.
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
Mark the placeholder configured to close that registration step.
Sidebar visibility is conditional on the company's plugin activation.
Actual browser/sidebar verification remains pending.

With the isolated test database and Redis available, run from `src/backend`:

```sh
php artisan test --filter=LibreSolarBmsRegistrationTest
```

Expect a nonzero passing test count covering central registration and tenant activation.
The test bootstrap must select `mpm_testing`; do not bypass that safeguard.

## Frontend handoff

The screen owner should begin only after the skeleton has been reviewed and merged.
The overview component is a generated placeholder; list/detail screens, translations, fault history, loading/error states and screenshots remain frontend work.
Backend work can proceed on dependent branches without treating those pending reviews as completed.

## Summary ingestion and ownership

The summary backend is implemented against the published proposed gateway contract at `66a4fbcf1bdda6dd008de45f8f95455408678f45`.
Owner review remains pending; the copied schemas and fixtures record source checksums in `src/backend/tests/fixtures/LibreSolarBms/provenance.json`.
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
These clock limits implement the proposed contract and still require owner review.
One current summary row is stored per battery; a small receipt ledger retains identity/fingerprint/outcome evidence for retries and conflicts, not raw telemetry.
There is currently no automatic ledger retention policy; deleting identity evidence would weaken replay/conflict detection.

User reads use JWT authentication and the tenant permission at `GET /api/libre-solar-bms/batteries` and `GET /api/libre-solar-bms/batteries/{id}`.
They return the contract's list/detail shapes, preserve the original summary object and distinguish null values, zero and known-clear fault arrays.
Collection queries accept positive integer `page` and `per_page` (maximum 100).
Invalid pagination returns 422; unknown battery IDs return 404 without searching another tenant.
Without a source-status check, integration freshness remains stale rather than pretending an old summary proves a live battery.

Run backend verification on the Linux host from `src/backend`, using only the isolated test database and Redis:

```sh
php artisan test --filter=LibreSolarBms
```

Expected: a nonzero test count including actual database writes, separate-company database isolation and shared contract fixtures.
Fixture tests are development evidence; gateway-to-MPM delivery and browser screenshots must be recorded separately.
