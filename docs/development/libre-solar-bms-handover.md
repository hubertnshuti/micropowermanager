# Libre Solar BMS backend handover

The plugin accepts normalized simulator messages through MPM's Laravel backend and returns company-scoped battery state and useful events.
It stores one current state per provisioned battery, rather than the one-second telemetry stream.
Start with the [plugin/API guide](libre-solar-bms-plugin.md) and [isolated Docker development guide](libre-solar-compose.md).

## Interface and ownership

Plugin ID is `33`; its generated frontend module is `src/frontend/src/plugins/libre-solar-bms/` and placeholder route is `/libre-solar-bms/overview`.
The placeholder is not a completed battery list/detail screen.
The backend module is `src/backend/app/Plugins/LibreSolarBms/`.

| Operation      | Endpoint                                         | Authentication                          |
| -------------- | ------------------------------------------------ | --------------------------------------- |
| Summary        | `POST /api/libre-solar-bms/ingest/summaries`     | Active company API key                  |
| Event          | `POST /api/libre-solar-bms/ingest/events`        | Active company API key                  |
| Metadata       | `POST /api/libre-solar-bms/ingest/attributes`    | Active company API key                  |
| Source check   | `POST /api/libre-solar-bms/ingest/status`        | Active company API key                  |
| Battery list   | `GET /api/libre-solar-bms/batteries`             | MPM user JWT and `libre-solar-bms.view` |
| Battery detail | `GET /api/libre-solar-bms/batteries/{id}`        | MPM user JWT and `libre-solar-bms.view` |
| Event history  | `GET /api/libre-solar-bms/batteries/{id}/events` | MPM user JWT and `libre-solar-bms.view` |

All operations require the plugin enabled for the authenticated company.
Ingestion cannot provision nodes, reassign company ownership or change the selected source/adapter binding.
Company resolution uses the existing API-key guard and a resolver registered only for the ingestion prefix.
Read routes retain normal MPM user authentication and tenant resolution.
Permission installation grants the existing tenant owner/admin roles; additional user access must be granted deliberately through MPM roles.

The copied request schemas and response examples live under `src/backend/tests/fixtures/LibreSolarBms/`.
Its `provenance.json` identifies gateway commit `66a4fbcf1bdda6dd008de45f8f95455408678f45` and file checksums.
These are proposed version-one interfaces awaiting owner review; publication does not imply teammate agreement.

## Data and display semantics

List and event endpoints return `data` and `pagination`, with positive integer `page` and `per_page` capped at 100.
Detail returns `data` containing identity, original summary/attributes/status and separate measurement/check/receipt timestamps.
Unknown fields remain null and zero remains zero.
Unsupported SoH and cycle count are not estimated.
Null fault information does not imply a healthy battery; an explicitly empty known fault list has a different meaning.
Both reported voltage fields and their quality information remain available; the backend does not derive terminal power from incompatible measurements.

Only an accepted summary can establish/promote the active producer session.
Within a session, old sequences cannot overwrite newer state; retired sessions cannot become current through replay.
New sessions require a newer observation within 150 seconds of receipt and no more than 5 seconds in the future.
Metadata has its own watermark and cannot promote sessions.
Events retain original observation time and separate receipt time, including previously unseen historical events from retired sessions.
Events sort by observed UTC milliseconds descending, then internal ID descending.
Page browsing can shift during concurrent inserts; it is not a snapshot cursor.

Duplicate accepted identities return `duplicate`; stale identities preserve their original stale result without refreshing state.
Changed content for the same identity is a conflict.
The transaction locks the battery row before checking durable identity fingerprints and updating state.
The receipt ledger retains fingerprints/outcomes, not raw telemetry; automatic ledger retention is not implemented.

Source status uses the provisioned adapter and an independent durable revision.
Original check time determines integration age; delayed receipt cannot make an old check fresh.
Integration becomes stale at 150 seconds without a new accepted check.
A 42-second-old summary can coexist with a recent source check.
The source's 15-second timeout belongs to the adapter and must not become a metric-row expiry rule.
The frontend should display source status and integration freshness separately and continue aging an accepted check.

## Frontend work and review still required

The generated module is ready for the frontend owner to extend after skeleton review/merge.
Battery list/detail/history, translations, loading/error states, unknown/fault/offline presentation, real browser screenshots and independent setup reproduction remain frontend work.
Do not present the generated placeholder or fixture requests as a working operator screen.
Battery placement on a dedicated plugin page versus attachment to an existing customer/device remains an owner decision; no existing ownership model was silently repurposed.

The integration maintainer and frontend owner must review authentication, ordering and the proposed interface before release.
The gateway currently forwards summaries/events/attributes but lacks durable HTTP retries/outbox, stored-event receipt publication and source-status delivery.
Those exact behaviors are required before claiming reliable end-to-end retry/freshness integration.
A backend fixture retry is not evidence that the gateway survives a lost HTTP response.

## Hardware and upstream boundaries

Future hardware producers must supply the same normalized contract, identity, quality and source information through the gateway boundary.
The PHP plugin does not depend on raw ThingSet/firmware field names and should not require raw-firmware parsing changes for that transport replacement.
Hardware commissioning, controls and hardware verification are outside this simulator contribution.

## Observed verification

On 15 October 2026, the backend changes committed as `d71b6432` were exercised with gateway commit `66a4fbcf1bdda6dd008de45f8f95455408678f45`.
The integration ran against the isolated Docker application, using an authenticated real Mosquitto broker and the existing gateway simulator and adapter.
The broker image was `eclipse-mosquitto@sha256:38c0da4f2ef84284d47b3b3eeea1cb3bdeabe81ee10caf0cd5c5ff61ee3ea408`.

| Check                                             | Observed result                                                                                   |
| ------------------------------------------------- | ------------------------------------------------------------------------------------------------- |
| Plugin and relevant existing authentication tests | 40 passed, 342 assertions                                                                         |
| Included provisioning/disposable migration tests  | 8 passed, 110 assertions                                                                          |
| Scoped PHPStan, Rector dry run and PHP formatting | Passed                                                                                            |
| Copied contract/schema provenance                 | All 52 file hashes matched                                                                        |
| Fresh isolated Docker initialization              | Both image builds, both locked installs, central/tenant migrations and first demo seed passed     |
| Runtime                                           | Backend health, frontend HTTP and demo login API returned 200; Horizon running                    |
| Repeat provisioning and full stop/restart         | One battery, one plugin activation and one named adapter key preserved                            |
| Source ownership                                  | Sampled files retained Linux UID/GID 1003:1003                                                    |
| Worker shutdown after explicit SIGTERM            | Exit 0, not OOM-killed; worker restarted                                                          |
| Actual simulated-source delivery                  | Summary and attributes stored; overvoltage raised/cleared as two events                           |
| Actual replay/conflict                            | Identical event returned duplicate; changed-content identity returned 409 without adding an event |
| Final integration storage counts                  | 1 battery, 1 current-state row, 2 events, 6 identity receipts                                     |
| Unsupported values/source freshness               | SoH and cycle count stayed null; absent status stayed null and integration stale                  |

The original interrupted aggregate quality command is not counted as passed; completed sequential baseline components and scoped feature checks supply the quality evidence.
The actual integration above used simulator-produced messages, separately from copied fixture tests.
It does not establish hardware behavior, concurrent request behavior, autonomous recovery after a lost HTTP response, browser login or a working battery screen.
Existing Predis nullable-parameter deprecation warnings appeared in PHP 8.5 diagnostic CLI output; the recorded commands exited successfully.

## Reproduce delivery with the existing gateway

First complete the Docker setup and explicit-company battery/key provisioning in the linked guides.
Keep those application services running; host PHP and Horizon terminals are not required.
Use a gateway checkout at the commit above, Node 24.21.0, and an authenticated MQTT broker reachable from the Linux terminal with ACLs permitting the selected node.
The simulator needs publish access and event-receipt subscription access for that node; the adapter needs read access to its topics.
Broker packaging and a combined gateway/MPM startup remain a separate integration deliverable.
The [pinned gateway adapter guide](https://github.com/hubertnshuti/oseas26-libre-solar-bms-monitoring-gateway/blob/66a4fbcf1bdda6dd008de45f8f95455408678f45/docs/mpm-ingest.md) describes the actual settings and current delivery limitations.

After loading the company key privately using the plugin guide, retain `MPM_API_KEY` in your shell without printing it.
From the gateway repository root, install its locked dependencies once if missing, then configure the adapter terminal:

```bash
cd /path/to/gateway
npm ci --no-audit --no-fund
export MPM_BASE_URL=http://127.0.0.1:18000
export MPM_COMPANY_BINDING=review-demo
export BMS_ADAPTER_ID=mpm-demo
export BMS_NODE_IDS=53494D0000000001
node --import tsx app/mpm-ingest/main.ts
```

Replace the checkout path and set `MQTT_URL`, `MQTT_USERNAME`, `MQTT_PASSWORD` to your broker endpoint and adapter credentials before starting.
The node, source and adapter must match provisioning; the local company label does not select a tenant.
Expected: a `subscribed` log, followed by accepted or duplicate outcomes when messages arrive.
Inspect startup/configuration errors before continuing; do not substitute a fake HTTP server for this check.
Do not repeat `npm ci` on every startup.

In a second Linux terminal, from the gateway root, set the publisher's broker credentials as `MQTT_USERNAME` and `MQTT_PASSWORD`, then run:

```bash
node --import tsx app/simulator/main.ts \
  --profile demo-lfp-4s-v1 --scenario cell-overvoltage --seed 17 \
  --node-id 53494D0000000001 --broker-url "$MQTT_URL" \
  --outbox-path .bms-runtime/mpm-review.events.json --consumer-id mpm-demo
```

Set `MQTT_URL` in this terminal to the same broker origin, such as your broker's loopback `mqtt://` endpoint.
Keep this scenario running for at least 13 seconds, then stop it with Ctrl+C and let the adapter drain before stopping it.
Expected: summary/attributes delivery and an overvoltage fault followed by clearance.
Use the plugin guide's JWT read command to find the returned battery ID, then read its detail and `/events` endpoints.
On an initially empty battery history, expect two events; existing history is preserved on later runs.
Producer event replays may continue because this adapter does not publish stored receipts; they must return duplicate without adding rows.
The simulator outbox remains diagnostic data; do not delete it to conceal missing receipt delivery.
If delivery fails, inspect controlled adapter outcomes and application logs before retrying; this adapter has no durable automatic HTTP retry.

Run the backend suite from `src/backend` on the Linux host with only the isolated test MySQL at port 53306 and Redis available:

```bash
php artisan test --stop-on-failure --filter='LibreSolarBms|AuthLoginTest|ThirdPartyApiResolverServiceTest|ApiKey'
```

Expected at this revision: 40 passing tests with 342 assertions.
The test bootstrap requires `mpm_testing`; never bypass that guard or point tests at the review/demo database.
Failures or interrupted commands must be investigated and recorded rather than counted as successful.

## Review scope

Team-only CI branch triggers are on the separate development-baseline branch.
The team base also contains earlier Composer-image fixes in three development Dockerfiles; review those separately when assembling an upstream proposal.
The standalone Compose runtime is development convenience and should be reviewed separately from production deployment policy.
The additive API-key expiry migration changes existing active-key lookup only when expiry is explicitly set; existing null-expiry keys retain their lifetime.

No teammate review, merge, actual CI result or upstream submission is implied by this handover.
Coordinate the issue and final PR destination with the team owner before submitting upstream.
