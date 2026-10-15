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
