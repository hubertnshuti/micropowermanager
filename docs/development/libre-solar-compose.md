# Isolated Libre Solar development runtime

This optional development Compose file starts MPM, Horizon, the Vue frontend, MySQL and Redis without separate host PHP terminals.
It reuses the repository's development Dockerfiles while bypassing their automatic installation, migration, seeding and recursive source ownership changes.
It does not start the operator dashboard, scheduler or a test database.
It is a local disposable demo, using the repository's published development credentials, and must not be exposed publicly.

**Validation status:** On 15 October 2026, the isolated Docker path passed both image builds, both locked dependency installations, fresh database initialization, backend/Horizon startup, frontend compilation and HTTP readiness, demo login API, repeated battery provisioning and restart with persisted data.
Browser login remains unverified; an API response does not demonstrate the authenticated browser interface.

## Prerequisites and isolation

Use Linux or a WSL Linux terminal, a full source checkout with its lockfiles, Docker Engine and the Docker Compose v2 plugin supporting `env_file` mappings.
Docker must have network access to image registries, Composer and npm registries.
Run installation/build commands one at a time and measure available memory before starting additional workloads.
On smaller machines, stop other disposable workloads first; preserve existing databases and volumes.
The PHP image builds from PHP 8.5 and the frontend uses the repository's pinned development image.
Images and dependency caches can require several gigabytes of disk space.

Run every command below from the MPM repository root in the same Linux terminal.
Select an unused project name and retain it for all subsequent commands.
The example project owns separate named volumes; it does not reuse another project's data.

```bash
cd /path/to/micropowermanager
export COMPOSE_PROJECT_NAME=mpm-bms-review
export COMPOSE_FILE=docker-compose.libre-solar.yml
export LOCAL_UID="$(id -u)"
export LOCAL_GID="$(id -g)"
docker compose config --quiet
```

Replace `/path/to/micropowermanager` with your clone's root.
Expected: configuration validation exits zero without output.
If it fails, resolve the reported Compose/configuration error before starting services.
Do not combine this file with the default Compose file or an existing installation's private override.

Only HTTP ports are published, bound to the local loopback interface.
The defaults are backend `18000` and frontend `18001`; MySQL and Redis remain internal.
If these HTTP ports are occupied, export unused `BMS_BACKEND_PORT` and `BMS_FRONTEND_PORT` values before initialization and use them in the URLs below.
The frontend API URL and backend frontend URL follow those variables.
No frontend security settings or firewall changes are required.

## Build and initialize a fresh disposable demo

These are initialization commands, not the normal startup command.
The `prepare` service changes ownership only inside this project's vendor, storage and bootstrap-cache volumes.
Application processes use your Linux UID/GID so they do not recursively change ownership of the source checkout.
Keep your existing untracked backend `.env`; Compose supplies database and Redis addresses for this network explicitly.
The backend uses `artisan serve --no-reload` so HTTP workers retain these container environment values instead of reloading host-local connection settings from that file.
The PHP services explicitly use `SIGTERM` because the shared Apache-based image defaults to `SIGWINCH`, which does not terminate the CLI Horizon process gracefully.

Build each distinct image sequentially:

```bash
docker compose build backend
docker compose build frontend
docker compose run --rm --no-deps prepare
docker compose run --rm --no-deps dependencies
docker compose run --rm --no-deps frontend npm ci --no-audit --no-fund
```

Expected: both image builds and both locked dependency installations exit zero.
Composer uses `composer.lock`; do not run `composer update` to resolve installation errors.
If a command fails, stop and inspect its error before proceeding; an interrupted install does not count as complete.
Do not run frontend compilation alongside Composer/PHP checks on a memory-constrained machine.

Start only the database and Redis, then initialize this new project's database:

```bash
docker compose up -d --wait mysql redis
docker compose run --rm --no-deps backend php artisan migrate --force
docker compose run --rm --no-deps backend php artisan db:seed --force
docker compose run --rm --no-deps backend php artisan migrate-tenant --force
```

Expected: MySQL/Redis become healthy and initialization commands exit zero, creating the central schema and demo tenant.
Seeding is an explicit one-time step for an empty disposable database.
Do not seed another installation or reset a database to make these commands pass.
If initialization fails, inspect that command's output and `docker compose logs --tail=80 mysql`; retain the volumes for diagnosis.
Plugin registration, activation and battery provisioning are separate feature steps; the base demo initializer does not claim to provision a Libre Solar battery.

## Start, verify and log in

After successful initialization, start the application with this single command:

```bash
docker compose up -d backend horizon frontend
docker compose ps
docker compose logs --tail=80 backend horizon frontend
```

Expected: backend becomes healthy, Horizon reports startup, and the frontend eventually reports successful compilation.
First compilation can take several minutes.
The frontend has a 1024 MiB Node heap and 1400 MiB container limit; backend and Horizon each have a 512 MiB container limit.
If any service exits, inspect its logs before retrying.
The memory limits constrain processes; they do not guarantee the whole stack fits alongside other workloads.

Check HTTP readiness and worker state:

```bash
curl --noproxy '*' --fail http://localhost:18000/up
curl --noproxy '*' --fail --output /dev/null http://localhost:18001
docker compose exec backend php artisan horizon:status
```

Expected: both HTTP requests return success and Horizon is running.
Open `http://localhost:18001` in your browser and log in with the published local demo account `demo_company_admin@example.com` / `123123`.
Confirm that the authenticated application opens; an HTTP 200 for the HTML or login API alone does not verify browser login.
On WSL, use a Windows browser and record whether localhost forwarding works for these exact ports.
If the CLI works but the browser times out, retain that distinction in the evidence and investigate forwarding/proxy behavior without disabling browser or firewall protections.

## Restart, persistence and troubleshooting

In the same repository and terminal environment, stop and restart safely:

```bash
docker compose stop
docker compose up -d backend horizon frontend
```

Expected: data persists and application services start without installation, migrations or seeding.
MySQL and Redis start through service dependencies if stopped.
After changing source revisions, explicitly run the required migrations and reinstall locked dependencies if their lockfiles changed before restarting application services.
Do not delete volumes as a routine fix.

Persistent volumes are scoped to `COMPOSE_PROJECT_NAME`: `mysql_data`, `redis_data`, `backend_vendor`, `backend_storage`, `backend_cache` and `frontend_node_modules`.
Keep the same project name to retain the same database.
Changing project names creates another isolated installation rather than migrating data.
`docker compose down` removes this project's containers/network while retaining named volumes; `down -v` deletes its stored data and is deliberately excluded from the workflow.

For an exited service, inspect logs and the container's termination state:

```bash
docker compose logs --tail=100 backend horizon frontend mysql redis
docker compose ps -a
docker stats --no-stream
```

Expected: logs identify connection, dependency or application errors and memory usage remains within available resources.
Exit 137 alone does not establish an out-of-memory kill; inspect the exited container's `State.OOMKilled` before making that claim.
Reduce concurrent workloads and retry the failed step sequentially if memory pressure is confirmed.
For permissions errors, verify `LOCAL_UID` and `LOCAL_GID`, stop application services, then rerun `prepare` for this project's volumes only.
Do not recursively chown the source checkout.

## Remaining verification and limitations

Record the exact MPM commit, Docker/Compose versions, initialization output, HTTP/worker checks and actual browser login result when performing the fresh reproduction.
Capture any displayed plugin behavior from the running application and identify whether its data came from fixtures or the gateway simulator.
The plugin's API, tenant isolation, retry/ordering tests and gateway integration evidence must be verified separately.
The development HTTP server and default demo credentials are unsuitable for production.
Scheduled jobs are not started by this minimal path; add an explicit scheduler service only if a demonstrated feature requires it.
This runtime does not supply a testing database, raw telemetry history, Home Assistant or the gateway MQTT services.
