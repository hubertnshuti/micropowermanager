# Libre Solar battery screens

The battery list is at `/libre-solar-bms/overview` after signing into MPM.
Selecting a battery opens `/libre-solar-bms/batteries/{id}` with its latest summary, separate source/integration assessments and paginated fault history.
The backend's internal battery ID selects the detail, not a caller-supplied company or node ID.
Read access uses the existing signed-in user token, an enabled LibreSolarBms plugin and `libre-solar-bms.view` permission.
Company integration API keys are never frontend settings.

## Start against an existing backend

Prerequisites: Docker Compose, the source checkout containing this frontend, and an already initialized backend with the plugin enabled and a provisioned battery.
The [backend Docker guide](libre-solar-compose.md) documents initialization of a new installation separately.
Do not initialize or seed an existing shared database for frontend development.
The default development frontend image pins Node 20.18; Gateway's Node version does not select the MPM runtime.

Run the following in a Bash terminal from the MPM repository root.
This standalone Compose file contains only the frontend, so it cannot start another backend or database stack.
It uses the repository's `docker/DockerfileFrontendDev`, with its pinned `node:20.18-alpine3.20` image.
Choose a project name unique to you if your Linux login already has another MPM frontend.
Keep this terminal open because the helper uses its shell variables.

```sh
export MPM_FRONTEND_PROJECT="${USER}-mpm"
mpm_frontend_root="$PWD"
mpm_frontend_compose="$(mktemp /tmp/mpm-frontend-compose.XXXXXX.yaml)"
cat > "$mpm_frontend_compose" <<'YAML'
services:
  frontend-dev:
    container_name: ${MPM_FRONTEND_PROJECT}-frontend
    build:
      context: .
      dockerfile: docker/DockerfileFrontendDev
    working_dir: /app
    entrypoint: ["/bin/sh", "-c", "npm ci && npm run serve -- --host 0.0.0.0"]
    environment:
      MPM_ENV: development
      MPM_BACKEND_URL: http://127.0.0.1:18000
      NODE_OPTIONS: --max-old-space-size=1024
    mem_limit: 1400m
    ports:
      - "127.0.0.1:8001:8080"
    volumes:
      - ./src/frontend:/app
      - frontend_node_modules:/app/node_modules
volumes:
  frontend_node_modules:
YAML
mpm_frontend() {
  docker compose --project-directory "$mpm_frontend_root" \
    --project-name "$MPM_FRONTEND_PROJECT" -f "$mpm_frontend_compose" "$@"
}
docker ps --format 'table {{.Names}}\t{{.Ports}}\t{{.Status}}'
mpm_frontend config
```

The explicit project directory makes the relative build context and source mount resolve to this checkout even though the generated Compose file is outside Git.
Inspect the effective configuration before starting: the source must be this checkout's `src/frontend`, container name must belong to your project, and the only published port must be `8001:8080`.
Compose creates a separate `${MPM_FRONTEND_PROJECT}_frontend_node_modules` volume for the locked dependencies.
If port 8001 is occupied, identify the existing service and select an unused port before continuing.
Never stop an unidentified service or remove another person's volumes.
The backend URL must be reachable from the browser; a Docker-only service name is insufficient.
For a remote browser, replace `127.0.0.1` with its actual reachable backend address before starting.
No integration key belongs in the frontend environment.

```sh
mpm_frontend up -d --no-deps --build frontend-dev
mpm_frontend logs --tail=80 frontend-dev
docker inspect "${MPM_FRONTEND_PROJECT}-frontend" \
  --format '{{json .Mounts}} {{json .NetworkSettings.Ports}}'
```

The first startup installs dependencies with `npm ci`; wait for the log message `Compiled successfully` before opening `http://localhost:8001`.
The backend must permit that browser origin through CORS.
Inspect the browser Network panel to verify that login and subsequent requests use `http://127.0.0.1:18000`, not the default port 8000.
The upstream local demo account is `demo_company_admin@example.com` / `123123`; use this only with the disposable local demo installation.
For an existing shared backend, obtain an authorized account with the plugin enabled and `libre-solar-bms.view` permission from its maintainer.
An HTTP 200 for HTML does not establish browser login.
Verify login in the browser, then open the battery list and detail and check their API responses.

Stop only this frontend before a separate production build, and resume it afterward:

```sh
mpm_frontend stop frontend-dev
mpm_frontend start frontend-dev
```

The upstream frontend entrypoint uses `npm install` and a relative `cd app`; the explicit working directory and entrypoint above avoid that path assumption and use locked dependencies.
Keep the generated file for subsequent commands in this terminal; a new terminal must recreate the variables and helper or supply the same explicit paths and project name.
This startup reuses an existing initialized backend and does not demonstrate a clean installation.

## Plugin setup

The registration wizard displays a compact setup card instead of embedding a second battery dashboard.
It verifies access to at least one registered battery before enabling `Finish plugin setup`.
An empty list or failed request keeps setup incomplete and offers another check.
Completing setup records the existing registration step; it does not claim that the source is currently connected.
Open Batteries from the sidebar for readings, history and freshness information.

## What the screen means

A simulated source is labeled `Simulated data` on list and detail.
A zero measurement keeps its number and unit; missing, unsupported or invalid values show `Not available` with a quality reason when supplied.
Display values use up to three decimal places for volts and amperes and one for percentages and temperatures.
API and stored values retain their original precision.
SoH and cycle count are unsupported by the pinned source and are not estimated.
Battery temperature and electronics temperature have different labels.
The current detail API exposes the electronics maximum, not individual IC/MOSFET/shunt readings.
There are no battery controls, health scores or timeseries charts in this view.

The list contains only fields returned by the list API, including the original adapter check time and its integration threshold.
It does not infer faults or temperature from missing list fields.
Both list and detail age the original adapter check independently of refresh success.
A check becomes stale at its API-provided threshold, currently 150 seconds.
The 15-second source timeout belongs to the adapter; the UI does not apply it to a summary intentionally sent every 60 seconds.
A 42-second-old summary can coexist with a recent fresh source check.
Check time, source observation, summary observation and backend receipt remain distinct.
After integration expires, the last source assessment is historical and retained readings are labeled last known.
Browser clock skew can affect relative ages; absolute UTC observations remain visible.

Only an explicit known-empty fault array and zero flags produce `No known active faults reported`.
Missing faults never imply healthy.
Unknown codes/bits stay visible as unrecognized fault information.
History records first observation, changes, clearances and observation gaps with original event times.
A first observation does not prove when a fault began; a gap does not prove recovery.
Pagination may shift when new events arrive because the API is page based, not a snapshot cursor.

Reads refresh 60 seconds after the preceding successful detail/history or list request completes.
Failed reads stop automatic refresh and offer a safe retry for request/network failures.
Permission, disabled-plugin, missing-battery and expired-session errors require the indicated user action.
Changing batteries clears prior data immediately and invalidates old responses.
Leaving the component clears both refresh and age timers.
After the real list loads, `Finish plugin setup` emits the generated plugin completion event for MPM's existing registration wizard.
The action is absent from fixture previews, empty/error states and detail views.

## Explicit development previews

Normal production behavior uses the real authenticated backend.
Fixture preview is compiled only into development and requires an explicit query parameter.
It is labeled `Fixture preview — not live integration` and does not write any backend data.
Copied fixture values come from the backend API examples; timestamps are adjusted relative to the preview clock to exercise freshness presentation.
Fault-history observations preserve their original fixture times.
This is UI evidence using constructed data, not MQTT-to-MPM integration evidence.

With the development frontend running and an authenticated session, open:

| Preview                      | URL                                              |
| ---------------------------- | ------------------------------------------------ |
| Normal list                  | `/libre-solar-bms/overview?fixture=normal`       |
| Normal detail                | `/libre-solar-bms/batteries/1?fixture=normal`    |
| Named faults/history         | `/libre-solar-bms/batteries/1?fixture=fault`     |
| Missing values/faults        | `/libre-solar-bms/batteries/1?fixture=unknown`   |
| Zero measurements            | `/libre-solar-bms/batteries/1?fixture=zero`      |
| No recent integration update | `/libre-solar-bms/batteries/1?fixture=stale`     |
| Empty list                   | `/libre-solar-bms/overview?fixture=empty`        |
| Request failure              | `/libre-solar-bms/batteries/1?fixture=error`     |
| Forbidden                    | `/libre-solar-bms/batteries/1?fixture=forbidden` |

Remove the fixture query to use real API reads.
Normal builds never silently substitute fixture data after a failure.

## Checks

From `src/frontend`, use Node 20.18 and install locked dependencies with `npm ci`.
Do not run production build concurrently with the development compiler on a memory-constrained machine.
Stop only your own frontend while building.

```sh
npm run test:battery
node node_modules/eslint/bin/eslint.js --no-fix src/plugins/libre-solar-bms src/__tests__/Battery*.test.js jest.battery.config.js scripts/testing/vueScriptTransformer.js src/ExportedRoutes.js
node node_modules/prettier/bin/prettier.cjs --check src/plugins/libre-solar-bms src/__tests__/Battery*.test.js jest.battery.config.js scripts/testing/vueScriptTransformer.js src/ExportedRoutes.js src/assets/locales/*.json
npm run i18n:check-json
MPM_ENV=development MPM_BACKEND_URL=http://127.0.0.1:18000 NODE_OPTIONS=--max-old-space-size=1536 npm run build
```

Recorded implementation validation covered 79 passing tests across three suites, a production build and the development compiler.
The production bundle excluded the fixture node ID and fixture-provider failure text while retaining the setup hook.
The frontend service accepted authenticated list/detail/history responses containing one simulated battery, stale integration and two events.
These results establish service compatibility with stored data; browser rendering and fresh MQTT scenarios require the separate acceptance checks below.
Record the source revision, command output and warnings when reproducing these checks.
The dedicated Jest configuration uses existing dependencies to exercise services, pure presentation logic and real Vue component scripts.
It does not mount templates or replace browser tests.
The repository's default `npm test` currently references a missing `setupTests.js` and fails validation before executing tests.
That baseline failure is not suppressed or counted as a passing check.
For a production build on a memory-constrained machine, use this temporary configuration from `src/frontend`, with the development compiler stopped:

```sh
mpm_build_config_dir="$(mktemp -d /tmp/mpm-build-config.XXXXXX)"
export MPM_FRONTEND_CONFIG="$PWD/vue.config.js"
printf '{"private":true,"type":"commonjs"}\n' > "$mpm_build_config_dir/package.json"
cat > "$mpm_build_config_dir/vue.config.js" <<'JS'
module.exports = { ...require(process.env.MPM_FRONTEND_CONFIG), parallel: false }
JS
MPM_ENV=development MPM_BACKEND_URL=http://127.0.0.1:18000 \
  VUE_CLI_SERVICE_CONFIG_PATH="$mpm_build_config_dir/vue.config.js" \
  NODE_OPTIONS=--max-old-space-size=1536 npm run build
```

Expect `Compiled successfully`; this changes build worker concurrency without changing application code or project dependencies.
The temporary directory needs its own package file because the existing Vue CLI configuration loader fails on a standalone file without one.
Production builds use MPM's existing runtime environment injection; follow the deployment entrypoint rather than serving an unconfigured `dist` folder.

## Design references

The [official frontend guide](https://micropowermanager.io/development/frontend.html) links the MPM Brand Guidelines 2026.
The view reuses MPM's Widget/cards/tables and existing blue/orange tokens.
Scoped Inter typography follows the guide and is bundled locally under the SIL Open Font License, avoiding a runtime font download.
The font source is [Google Fonts' Inter directory](https://github.com/google/fonts/tree/main/ofl/inter).
Existing global theme differences are not changed by this plugin.
English keys are mirrored in all locale files; the new non-English entries are English placeholders awaiting translation review.

## Troubleshooting

If API requests still use port 8000, inspect `mpm_frontend config` and recreate only your frontend with `mpm_frontend up -d --no-deps --force-recreate frontend-dev` after correcting its environment.
If login is blocked by CORS, record the request origin, preflight response and browser error for the backend maintainer rather than disabling browser protections.
For a 403 battery response, distinguish `plugin_disabled` from `forbidden` in the response code and ask the maintainer to verify plugin activation or the account's view permission.
A successful login with an empty list can mean no batteries are provisioned for that account's tenant; do not seed or reset the shared database.
For exit 137, check Docker's available memory and coordinate resource use; keep development compilation and production builds sequential.
If HTML downloads but browser navigation times out, record the browser URL and exact error separately from curl results.

## Current limitations

The following limits apply to the current screens and their API:

- Detail lacks individual electronics measurements; only the combined maximum is shown.
- Copied detail examples omit `summary_received_at`; missing fixture receipt time remains unavailable.
- Gateway revision `66a4fbc` lacks source-status delivery, durable HTTP retry/outbox and stored-event receipts.
- The focused battery suite runs in CI but does not mount Vue templates; browser acceptance remains a separate check.

Capture browser acceptance, current API integration scenarios and independent installation results with exact revisions.
An existing backend with retained data does not establish a clean installation.

## Manual browser acceptance record

Run these steps against the development frontend after successful browser login.
Record the browser version, source revision, viewport and pass/fail result; capture actual images only after the indicated screen appears.

1. Open the real list, select its returned battery ID and compare values and observation times with the signed-in API response.
2. Open each explicit normal/fault/unknown/zero/stale/error preview URL above and confirm its fixture banner.
3. Confirm zero shows a number and unit, missing faults say unavailable, and named/unknown faults never appear healthy.
4. On the normal detail preview, wait until the original check reaches 150 seconds and confirm that integration becomes stale without a new status check.
5. Disconnect browser networking, refresh a successfully loaded real detail and check retained values are labeled outdated with a retry action.
6. Restore networking, retry once and confirm the error clears after a successful read.
7. Browse history pages; switch batteries while requests are slowed and confirm no prior battery values replace the selected battery.
8. Navigate away and confirm polling stops in Network; return and confirm only one refresh sequence is active.
9. Use Tab/Enter for controls, 200% zoom and a narrow viewport; confirm readable labels, accessible history scrolling and no reliance on color alone.
10. Capture normal, named fault/history, unavailable/unknown, stale and request-error screens; label fixture images as fixtures and keep credentials/tokens out of images.

For independent full-stack reproduction, use fresh isolated data and record both the gateway and MPM revisions.
