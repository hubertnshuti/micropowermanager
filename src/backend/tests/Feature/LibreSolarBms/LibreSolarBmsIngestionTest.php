<?php

namespace Tests\Feature\LibreSolarBms;

use App\Models\ApiKey;
use App\Models\Auth\Permission;
use App\Models\Company;
use App\Models\MpmPlugin;
use App\Models\Plugins;
use App\Models\User;
use App\Plugins\LibreSolarBms\Models\Battery;
use App\Plugins\LibreSolarBms\Services\BatteryService;
use App\Services\DatabaseProxyManagerService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use RuntimeException as ReceiptPersistenceFailure;
use Tests\TestCase;

class LibreSolarBmsIngestionTest extends TestCase {
    private string $token;
    private Battery $battery;

    protected function setUp(): void {
        parent::setUp();
        $this->token = 'libre-solar-test-'.bin2hex(random_bytes(16));
        Company::findOrFail($this->companyId)->apiKeys()->create([
            'name' => 'Libre Solar test',
            'token_hash' => hash('sha256', $this->token),
            'active' => true,
        ]);
        Plugins::updateOrCreate(['mpm_plugin_id' => MpmPlugin::LIBRE_SOLAR_BMS], ['status' => Plugins::ACTIVE]);
        $this->battery = Battery::create([
            'node_id' => '53494D0000000001',
            'label' => 'Test battery',
            'adapter_id' => 'mpm-demo',
            'source' => 'simulator',
        ]);
    }

    protected function tearDown(): void {
        ApiKey::where('token_hash', hash('sha256', $this->token))->delete();
        parent::tearDown();
    }

    public function testSummaryPreservesZeroAndUnknownAndKeepsOneCurrentState(): void {
        $summary = $this->fixture('normal.summary');
        $summary['soc'] = 0;
        $summary['cell_voltage_delta'] = 0;
        $this->ingest('summaries', $summary)->assertSuccessful()->assertJsonPath('result', 'accepted');
        $summary = $this->withSequence($summary, 122, 'summary');
        $summary['soc'] = 1;
        $this->ingest('summaries', $summary)->assertSuccessful();

        $this->readBattery()->assertOk()
            ->assertJsonPath('data.summary.soc', 1)
            ->assertJsonPath('data.summary.cell_voltage_delta', 0)
            ->assertJsonPath('data.summary.soh', null)
            ->assertJsonPath('data.summary.error_flags', 0)
            ->assertJsonPath('data.summary.faults', []);
        $this->assertSame(1, DB::connection('tenant')->table('libre_solar_bms_battery_states')->count());
    }

    public function testReplaySucceedsButChangedContentForSameIdentityConflicts(): void {
        $summary = $this->fixture('normal.summary');
        $this->ingest('summaries', $summary)->assertSuccessful()->assertJsonPath('result', 'accepted');
        $this->ingest('summaries', $summary)->assertSuccessful()->assertJsonPath('result', 'duplicate');
        $summary['soc'] = 1;
        $this->ingest('summaries', $summary)->assertStatus(409)->assertJsonPath('code', 'id_conflict');
        $this->readBattery()->assertJsonPath('data.summary.soc', 74.2);
    }

    public function testReceiptWriteFailureRollsBackSummaryAndAllowsTheOriginalRequestToRetry(): void {
        $failReceipt = true;
        DB::listen(function (QueryExecuted $query) use (&$failReceipt): void {
            if ($failReceipt && str_starts_with($query->sql, 'insert into `libre_solar_bms_receipts`')) {
                throw new ReceiptPersistenceFailure('Simulated receipt persistence failure');
            }
        });
        $summary = $this->fixture('normal.summary');
        try {
            app(BatteryService::class)->ingest('summary', $summary, json_encode($summary, JSON_THROW_ON_ERROR));
            $this->fail('The receipt failure should abort ingestion');
        } catch (ReceiptPersistenceFailure $exception) {
            $this->assertSame('Simulated receipt persistence failure', $exception->getMessage());
        } finally {
            $failReceipt = false;
        }
        $this->assertSame(0, DB::connection('tenant')->table('libre_solar_bms_battery_states')->count());
        $this->assertSame(0, DB::connection('tenant')->table('libre_solar_bms_receipts')->count());
        $this->ingest('summaries', $summary)->assertSuccessful()->assertJsonPath('result', 'accepted');
        $this->ingest('summaries', $summary)->assertSuccessful()->assertJsonPath('result', 'duplicate');
        $this->assertSame(1, DB::connection('tenant')->table('libre_solar_bms_receipts')->count());
        $this->readBattery()->assertJsonPath('data.summary.soc', 74.2);
    }

    public function testOlderSequenceCannotOverwriteCurrentSummary(): void {
        $summary = $this->fixture('normal.summary');
        $newer = $this->withSequence($summary, 125, 'summary');
        $newer['soc'] = 80;
        $this->ingest('summaries', $newer)->assertSuccessful();
        $this->ingest('summaries', $summary)->assertSuccessful()->assertJsonPath('result', 'ignored_stale');
        $this->readBattery()->assertJsonPath('data.summary.soc', 80);
    }

    public function testNewSessionRequiresNewerObservationAndSupersededSessionCannotReturn(): void {
        $this->travelTo(now()->setDate(2026, 10, 8)->setTime(14, 1));
        $original = $this->fixture('normal.summary');
        $this->ingest('summaries', $original)->assertSuccessful();
        $newSession = $original;
        $newSession['session_id'] = '25f75ec4-29ab-48ab-93be-81d3e0708a68';
        $newSession = $this->withSequence($newSession, 1, 'summary');
        $newSession['telemetry_message_id'] = $newSession['node_id'].':'.$newSession['session_id'].':telemetry:0';
        $this->ingest('summaries', $newSession)->assertStatus(409);
        $newSession = $this->withSequence($newSession, 2, 'summary');
        $newSession['observed_at'] = '2026-10-08T14:01:00.000Z';
        $newSession['published_at'] = $newSession['observed_at'];
        $newSession['last_source_observed_at'] = $newSession['observed_at'];
        $newSession['soc'] = 82;
        $this->ingest('summaries', $newSession)->assertSuccessful();
        $delayed = $this->withSequence($original, 500, 'summary');
        $delayed['observed_at'] = '2026-10-08T14:02:00.000Z';
        $delayed['published_at'] = $delayed['observed_at'];
        $delayed['last_source_observed_at'] = $delayed['observed_at'];
        $this->ingest('summaries', $delayed)->assertSuccessful()->assertJsonPath('result', 'ignored_stale');
        $this->readBattery()->assertJsonPath('data.summary.soc', 82);
        $this->travelBack();
    }

    public function testSummaryReplayFingerprintIgnoresObjectKeyOrderAndEquivalentNumbers(): void {
        $summary = $this->fixture('normal.summary');
        $summary['soc'] = 5;
        $this->ingest('summaries', $summary)->assertSuccessful()->assertJsonPath('result', 'accepted');
        $reordered = array_reverse($summary, true);
        $reordered['quality'] = array_reverse($reordered['quality'], true);
        $reordered['soc'] = 5.0;
        auth()->forgetGuards();
        $this->call('POST', '/api/libre-solar-bms/ingest/summaries', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], json_encode($reordered, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION))
            ->assertSuccessful()->assertJsonPath('result', 'duplicate');
    }

    public function testSummaryReplayTreatsSignedZeroAndExponentNotationAsEquivalentNumbers(): void {
        foreach ([[121, '0', '-0.0'], [122, '100000000000000000', '1e17']] as [$sequence, $firstNumber, $replayedNumber]) {
            $summary = $this->withSequence($this->fixture('normal.summary'), $sequence, 'summary');
            $json = json_encode($summary, JSON_THROW_ON_ERROR);
            foreach ([[$firstNumber, 'accepted'], [$replayedNumber, 'duplicate']] as [$number, $result]) {
                $body = preg_replace('/("cell_temperature_max":)[0-9.]+/', '${1}'.$number, $json);
                auth()->forgetGuards();
                app('tymon.jwt')->unsetToken();
                $this->call('POST', '/api/libre-solar-bms/ingest/summaries', [], [], [], [
                    'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
                    'CONTENT_TYPE' => 'application/json',
                    'HTTP_ACCEPT' => 'application/json',
                ], $body)->assertSuccessful()->assertJsonPath('result', $result);
            }
        }
        $this->assertSame(1, DB::connection('tenant')->table('libre_solar_bms_battery_states')->count());
    }

    public function testInvalidSummaryPayloadsNeverWriteCurrentState(): void {
        $summary = $this->fixture('normal.summary');
        $invalid = [];
        $invalid[] = [...$summary, 'company_id' => $this->companyId];
        $invalid[] = [...$summary, 'schema_version' => 2];
        $invalid[] = [...$summary, 'soc' => '74.2'];
        $invalid[] = [...$summary, 'soc' => null];
        $invalid[] = [...$summary, 'error_flags' => null];
        $invalid[] = [...$summary, 'message_id' => 'unrelated-id'];
        $invalid[] = [...$summary, 'observed_at' => 'not-a-timestamp'];
        $invalid[] = [...$summary, 'last_source_observed_at' => '2026-10-08T13:59:00.000Z'];
        foreach ($invalid as $payload) {
            $this->ingest('summaries', $payload)->assertStatus(422);
        }
        $this->assertSame(0, DB::connection('tenant')->table('libre_solar_bms_battery_states')->count());
        $this->assertSame(0, DB::connection('tenant')->table('libre_solar_bms_receipts')->count());
    }

    public function testIngestionRejectsInvalidRequestEnvelopesWithoutWriting(): void {
        $valid = json_encode($this->fixture('normal.summary'), JSON_THROW_ON_ERROR);
        foreach ([
            ['text/plain', $valid, 415],
            ['application/json', str_repeat(' ', 32769), 413],
            ['application/json', '{', 422],
            ['application/json', '[]', 422],
            ['application/json', 'null', 422],
        ] as [$contentType, $body, $status]) {
            auth()->forgetGuards();
            app('tymon.jwt')->unsetToken();
            $this->call('POST', '/api/libre-solar-bms/ingest/summaries', [], [], [], [
                'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
                'CONTENT_TYPE' => $contentType,
                'HTTP_ACCEPT' => 'application/json',
            ], $body)->assertStatus($status)->assertJsonPath('result', 'rejected');
        }
        $this->assertSame(0, DB::connection('tenant')->table('libre_solar_bms_battery_states')->count());
        $this->assertSame(0, DB::connection('tenant')->table('libre_solar_bms_receipts')->count());
    }

    public function testBatteryListExposesOriginalStatusCheckAndAgesAtTheSameBoundaryAsDetail(): void {
        $this->travelTo(now()->setDate(2026, 10, 8)->setTime(14, 0));
        $this->getJson('/api/libre-solar-bms/batteries', $this->readerHeaders())
            ->assertOk()->assertJsonPath('data.0.status_checked_at', null)
            ->assertJsonPath('data.0.integration_stale_after_seconds', 150);
        $status = $this->fixture('fresh.status');
        $this->ingest('status', $status)->assertSuccessful();
        $this->travel(149)->seconds();
        $this->getJson('/api/libre-solar-bms/batteries', $this->readerHeaders())
            ->assertOk()->assertJsonPath('data.0.status_checked_at', $status['status_checked_at'])
            ->assertJsonPath('data.0.integration_status', 'recent');
        $this->travel(1)->seconds();
        $this->getJson('/api/libre-solar-bms/batteries', $this->readerHeaders())
            ->assertOk()->assertJsonPath('data.0.status_checked_at', $status['status_checked_at'])
            ->assertJsonPath('data.0.integration_status', 'stale');
        $this->travelBack();
    }

    public function testBatteryPaginationRejectsMalformedOrExcessiveInputs(): void {
        $headers = $this->readerHeaders();
        foreach (['page=0', 'page=-1', 'page=1.5', 'page=1e2', 'page[]=1', 'page='.PHP_INT_MAX.'&per_page=25', 'per_page=0', 'per_page=101', 'per_page=25x', 'per_page=99999999999999999999999'] as $query) {
            $this->getJson('/api/libre-solar-bms/batteries?'.$query, $headers)->assertStatus(422);
        }
        $this->getJson('/api/libre-solar-bms/batteries?page=2&per_page=1', $headers)
            ->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('pagination.page', 2)->assertJsonPath('pagination.per_page', 1);
    }

    public function testIdenticalNodeStringsWriteToSeparateCompanyDatabases(): void {
        $central = DB::connection('micro_power_manager');
        $this->assertSame('mpm_testing', $central->getDatabaseName());
        $tenant = DB::connection('tenant');
        $firstDatabase = $tenant->getDatabaseName();
        $tenant->rollBack();
        $secondDatabase = 'LibreSolarBmsIsolation_'.bin2hex(random_bytes(4));
        $central->statement('CREATE DATABASE `'.$secondDatabase.'`');
        $secondCompany = null;
        try {
            foreach (['plugins', 'libre_solar_bms_batteries', 'libre_solar_bms_battery_states', 'libre_solar_bms_receipts', 'libre_solar_bms_events'] as $table) {
                if ($table === 'libre_solar_bms_events' && !Schema::connection('tenant')->hasTable($table)) {
                    continue;
                }
                $central->statement('CREATE TABLE `'.$secondDatabase.'`.`'.$table.'` LIKE `'.$firstDatabase.'`.`'.$table.'`');
            }
            $tenant->beginTransaction();
            Plugins::updateOrCreate(['mpm_plugin_id' => MpmPlugin::LIBRE_SOLAR_BMS], ['status' => Plugins::ACTIVE]);
            $this->battery = Battery::create(['node_id' => '53494D0000000001', 'label' => 'Test battery', 'adapter_id' => 'mpm-demo', 'source' => 'simulator']);
            $secondCompany = Company::create(['name' => 'Isolated BMS test', 'email' => 'isolated-bms@example.com', 'address' => 'Test', 'phone' => '+255000456789', 'country_id' => -1]);
            $secondToken = 'second-'.bin2hex(random_bytes(16));
            $secondCompany->apiKeys()->create(['token_hash' => hash('sha256', $secondToken), 'active' => true]);
            $manager = \Mockery::mock(app(DatabaseProxyManagerService::class))->makePartial();
            $manager->shouldReceive('runForCompany')->andReturnUsing(function (int $companyId, callable $callback) use ($tenant, $firstDatabase, $secondDatabase, $secondCompany) {
                $database = $companyId === $secondCompany->id ? $secondDatabase : $firstDatabase;
                $tenant->statement('USE `'.$database.'`');
                $tenant->setDatabaseName($database);

                return $callback();
            });
            $this->app->instance(DatabaseProxyManagerService::class, $manager);
            $summary = $this->fixture('normal.summary');
            $this->ingest('summaries', $summary)->assertSuccessful();
            $tenant->statement('USE `'.$secondDatabase.'`');
            $tenant->setDatabaseName($secondDatabase);
            Plugins::create(['mpm_plugin_id' => MpmPlugin::LIBRE_SOLAR_BMS, 'status' => Plugins::ACTIVE]);
            $this->ingest('summaries', $summary, $secondToken)->assertNotFound();
            Battery::create(['node_id' => $summary['node_id'], 'label' => 'Second battery', 'source' => 'simulator', 'adapter_id' => 'mpm-demo']);
            $summary['soc'] = 5;
            $this->ingest('summaries', $summary, $secondToken)->assertSuccessful()->assertJsonPath('result', 'accepted');
            $this->assertSame(5, json_decode($tenant->table('libre_solar_bms_battery_states')->value('summary'), true)['soc']);
            $tenant->statement('USE `'.$firstDatabase.'`');
            $tenant->setDatabaseName($firstDatabase);
            $this->assertSame(74.2, json_decode($tenant->table('libre_solar_bms_battery_states')->value('summary'), true)['soc']);
        } finally {
            $tenant->statement('USE `'.$firstDatabase.'`');
            $tenant->setDatabaseName($firstDatabase);
            if ($tenant->transactionLevel() > 0) {
                $tenant->rollBack();
            }
            $tenant->beginTransaction();
            if ($secondCompany !== null) {
                $secondCompany->apiKeys()->delete();
                $secondCompany->delete();
            }
            $central->statement('DROP DATABASE `'.$secondDatabase.'`');
        }
    }

    public function testEventsAreStoredOnceWithOriginalObservationTimeAndPaginated(): void {
        $event = $this->fixture('fault.event');
        $this->ingest('events', $event)->assertSuccessful();
        $this->ingest('events', $event)->assertSuccessful()->assertJsonPath('result', 'duplicate');
        $changed = $event;
        $changed['published_at'] = '2026-10-08T14:00:01.000Z';
        $this->ingest('events', $changed)->assertStatus(409);
        $second = $this->withSequence($event, 124, 'events');
        $second['event_id'] = $second['message_id'];
        $this->ingest('events', $second)->assertSuccessful();
        $this->assertSame(2, DB::connection('tenant')->table('libre_solar_bms_events')->count());
        $this->getJson('/api/libre-solar-bms/batteries/'.$this->battery->id.'/events?per_page=1&page=2', $this->readerHeaders())
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('pagination.total', 2)
            ->assertJsonPath('pagination.page', 2)->assertJsonPath('data.0.event.observed_at', $event['observed_at']);
    }

    public function testEventHistorySortsByOriginalMillisecondsRatherThanReceiptOrder(): void {
        $later = $this->fixture('fault.event');
        $later['observed_at'] = '2026-10-08T14:00:00.900Z';
        $later['published_at'] = $later['observed_at'];
        $earlier = $this->withSequence($later, 124, 'events');
        $earlier['event_id'] = $earlier['message_id'];
        $earlier['observed_at'] = '2026-10-08T14:00:00.100Z';
        $earlier['published_at'] = $earlier['observed_at'];
        $this->ingest('events', $later)->assertSuccessful();
        $this->ingest('events', $earlier)->assertSuccessful();
        $this->getJson('/api/libre-solar-bms/batteries/'.$this->battery->id.'/events', $this->readerHeaders())
            ->assertOk()->assertJsonPath('pagination.total', 2)
            ->assertJsonPath('data.0.event.observed_at', $later['observed_at'])
            ->assertJsonPath('data.1.event.observed_at', $earlier['observed_at']);
    }

    public function testEventHistoryPreservesContractDatesOutsideMysqlTimestampRange(): void {
        $summary = $this->fixture('normal.summary');
        $this->ingest('summaries', $summary)->assertSuccessful();
        $historical = $this->fixture('fault.event');
        $historical['observed_at'] = '1960-01-01T00:00:00.100Z';
        $historical['published_at'] = $historical['observed_at'];
        $future = $this->withSequence($historical, 124, 'events');
        $future['event_id'] = $future['message_id'];
        $future['observed_at'] = '2040-01-01T00:00:00.900Z';
        $future['published_at'] = $future['observed_at'];
        $this->ingest('events', $historical)->assertSuccessful();
        $this->ingest('events', $future)->assertSuccessful();
        $this->getJson('/api/libre-solar-bms/batteries/'.$this->battery->id.'/events', $this->readerHeaders())
            ->assertOk()->assertJsonPath('pagination.total', 2)
            ->assertJsonPath('data.0.event.observed_at', $future['observed_at'])
            ->assertJsonPath('data.1.event.observed_at', $historical['observed_at']);
        $this->readBattery()->assertJsonPath('data.summary.observed_at', $summary['observed_at'])
            ->assertJsonPath('data.summary.error_flags', 0);
    }

    public function testDelayedStatusRetainsItsOriginalCheckTimeAndDoesNotRefreshOnReplay(): void {
        $this->travelTo(now()->setDate(2026, 10, 8)->setTime(14, 10));
        $status = $this->fixture('fresh.status');
        $this->ingest('status', $status)->assertSuccessful();
        $first = $this->readBattery()->assertJsonPath('data.status.status_checked_at', $status['status_checked_at'])->json('data.status_received_at');
        $this->travel(5)->minutes();
        $this->ingest('status', $status)->assertSuccessful()->assertJsonPath('result', 'duplicate');
        $this->readBattery()->assertJsonPath('data.status_received_at', $first)
            ->assertJsonPath('data.integration_status', 'stale');
        $older = $status;
        $older['status_revision'] = 0;
        $older['message_id'] = $older['node_id'].':mpm-demo:status:0';
        $older['source_status'] = 'disconnected';
        $this->ingest('status', $older)->assertSuccessful()->assertJsonPath('result', 'ignored_stale');
        $this->readBattery()->assertJsonPath('data.status.status_revision', 1);
        $this->travelBack();
    }

    public function testAttributesCannotProvisionOrReassignNodeOwnership(): void {
        $attributes = $this->fixture('demo.attributes');
        $this->ingest('attributes', $attributes)->assertSuccessful();
        $this->readBattery()->assertJsonPath('data.attributes.profile_id', 'demo-lfp-4s-v1');
        $attributes['company_id'] = $this->companyId + 1;
        $this->ingest('attributes', $attributes)->assertStatus(422);
        $summary = $this->fixture('normal.summary');
        $summary['node_id'] = '53494D0000000002';
        $summary['message_id'] = str_replace('53494D0000000001', $summary['node_id'], $summary['message_id']);
        $summary['telemetry_message_id'] = str_replace('53494D0000000001', $summary['node_id'], $summary['telemetry_message_id']);
        $this->ingest('summaries', $summary)->assertStatus(404);
        $this->assertSame(1, Battery::count());
    }

    public function testStatusConflictsCannotRegressCheckTimeOrAcceptFutureChecks(): void {
        $this->travelTo(now()->setDate(2026, 10, 8)->setTime(14, 0));
        $status = $this->fixture('fresh.status');
        $this->ingest('status', $status)->assertSuccessful();
        $changed = [...$status, 'source_status' => 'disconnected'];
        $this->ingest('status', $changed)->assertStatus(409)->assertJsonPath('code', 'status_revision_conflict');
        $regressing = [...$status, 'status_revision' => 2, 'message_id' => $status['node_id'].':mpm-demo:status:2',
            'status_checked_at' => '2026-10-08T13:59:59.000Z', 'last_source_observed_at' => '2026-10-08T13:59:59.000Z'];
        $this->ingest('status', $regressing)->assertStatus(409);
        $future = [...$status, 'status_revision' => 3, 'message_id' => $status['node_id'].':mpm-demo:status:3',
            'status_checked_at' => '2026-10-08T14:00:06.000Z'];
        $this->ingest('status', $future)->assertStatus(409);
        $this->readBattery()->assertJsonPath('data.status.status_revision', 1)
            ->assertJsonPath('data.status.status_checked_at', $status['status_checked_at']);
        $this->travelBack();
    }

    public function testMinuteSummaryRemainsRecentUntilIntegrationThreshold(): void {
        $this->travelTo(now()->setDate(2026, 10, 8)->setTime(14, 0));
        $summary = $this->fixture('normal.summary');
        $this->ingest('summaries', $summary)->assertSuccessful();
        $this->ingest('status', $this->fixture('fresh.status'))->assertSuccessful();
        $this->travel(42)->seconds();
        $this->readBattery()->assertJsonPath('data.integration_status', 'recent')
            ->assertJsonPath('data.status.source_status', 'fresh')
            ->assertJsonPath('data.metric_observed_at', $summary['observed_at']);
        $this->travel(108)->seconds();
        $this->readBattery()->assertJsonPath('data.integration_status', 'stale');
        $this->travelBack();
    }

    public function testAttributesCannotPromoteAnotherProducerSession(): void {
        $this->travelTo(now()->setDate(2026, 10, 8)->setTime(14, 1));
        $summary = $this->fixture('normal.summary');
        $this->ingest('summaries', $summary)->assertSuccessful();
        $attributes = $this->fixture('demo.attributes');
        $attributes['session_id'] = '25f75ec4-29ab-48ab-93be-81d3e0708a68';
        $attributes = $this->withSequence($attributes, 0, 'attributes');
        $attributes['observed_at'] = '2026-10-08T14:01:00.000Z';
        $attributes['published_at'] = $attributes['observed_at'];
        $this->ingest('attributes', $attributes)->assertStatus(409);
        $this->assertSame($summary['session_id'], $this->battery->fresh()->active_session_id);
        $this->readBattery()->assertJsonPath('data.attributes', null);
        $this->travelBack();
    }

    public function testSupersededSessionEventsRemainUsefulWithoutReplacingMetrics(): void {
        $this->travelTo(now()->setDate(2026, 10, 8)->setTime(14, 1));
        $original = $this->fixture('normal.summary');
        $this->ingest('summaries', $original)->assertSuccessful();
        $newer = $original;
        $newer['session_id'] = '25f75ec4-29ab-48ab-93be-81d3e0708a68';
        $newer = $this->withSequence($newer, 2, 'summary');
        $newer['telemetry_message_id'] = $newer['node_id'].':'.$newer['session_id'].':telemetry:1';
        $newer['observed_at'] = '2026-10-08T14:01:00.000Z';
        $newer['published_at'] = $newer['observed_at'];
        $newer['last_source_observed_at'] = $newer['observed_at'];
        $newer['soc'] = 85;
        $this->ingest('summaries', $newer)->assertSuccessful();
        $event = $this->fixture('fault.event');
        $this->ingest('events', $event)->assertSuccessful()->assertJsonPath('result', 'accepted');
        $this->ingest('events', $event)->assertSuccessful()->assertJsonPath('result', 'duplicate');
        $this->readBattery()->assertJsonPath('data.summary.soc', 85)
            ->assertJsonPath('data.summary.error_flags', 0)
            ->assertJsonPath('data.summary.observed_at', $newer['observed_at']);
        $this->getJson('/api/libre-solar-bms/batteries/'.$this->battery->id.'/events', $this->readerHeaders())
            ->assertOk()->assertJsonPath('pagination.total', 1)->assertJsonPath('data.0.event.session_id', $original['session_id']);
        $this->travelBack();
    }

    public function testMissingUnknownRevokedAndExpiredKeysAreRejected(): void {
        $summary = $this->fixture('normal.summary');
        $this->postJson('/api/libre-solar-bms/ingest/summaries', $summary)->assertUnauthorized();
        $this->ingest('summaries', $summary, 'unknown-token')->assertUnauthorized();
        ApiKey::where('token_hash', hash('sha256', $this->token))->update(['active' => false]);
        $this->ingest('summaries', $summary)->assertUnauthorized();
        ApiKey::where('token_hash', hash('sha256', $this->token))->update(['active' => true, 'expires_at' => now()->subMinute()]);
        $this->ingest('summaries', $summary)->assertUnauthorized();
        $this->assertSame(0, DB::connection('tenant')->table('libre_solar_bms_battery_states')->count());
    }

    public function testSummaryCannotProvisionUnregisteredNodeOwnership(): void {
        $summary = $this->fixture('normal.summary');
        $summary['node_id'] = '53494D0000000002';
        $summary['message_id'] = str_replace('53494D0000000001', $summary['node_id'], $summary['message_id']);
        $summary['telemetry_message_id'] = str_replace('53494D0000000001', $summary['node_id'], $summary['telemetry_message_id']);
        $this->ingest('summaries', $summary)->assertNotFound();
        $this->assertSame(1, Battery::count());
        $this->assertSame(0, DB::connection('tenant')->table('libre_solar_bms_battery_states')->count());
    }

    public function testDisabledPluginRejectsIngestAndAuthenticatedReads(): void {
        Plugins::where('mpm_plugin_id', MpmPlugin::LIBRE_SOLAR_BMS)->update(['status' => Plugins::INACTIVE]);
        $this->ingest('summaries', $this->fixture('normal.summary'))->assertForbidden();
        $this->readBattery()->assertForbidden();
    }

    public function testUiReaderNeedsJwtAndPluginPermission(): void {
        $this->getJson('/api/libre-solar-bms/batteries')->assertUnauthorized();
        $user = User::create(['name' => 'No permission', 'email' => 'no-bms@example.com', 'password' => 'test-password', 'company_id' => $this->companyId]);
        $this->getJson('/api/libre-solar-bms/batteries', $this->headers($user))->assertForbidden();
        $this->getJson('/api/libre-solar-bms/batteries', $this->readerHeaders())->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/libre-solar-bms/batteries/999999', $this->readerHeaders())->assertNotFound();
    }

    /** @return array<string, mixed> */
    private function fixture(string $name): array {
        return json_decode(file_get_contents(base_path('tests/fixtures/LibreSolarBms/v1/valid/'.$name.'.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $payload */
    private function ingest(string $kind, array $payload, ?string $token = null): TestResponse {
        auth()->forgetGuards();
        app('tymon.jwt')->unsetToken();

        return $this->postJson('/api/libre-solar-bms/ingest/'.$kind, $payload, ['Authorization' => 'Bearer '.($token ?? $this->token)]);
    }

    private function readBattery(): TestResponse {
        return $this->getJson('/api/libre-solar-bms/batteries/'.$this->battery->id, $this->readerHeaders());
    }

    /** @return array<string, string> */
    private function readerHeaders(): array {
        $user = User::firstOrCreate(['email' => 'bms-reader@example.com'], ['name' => 'BMS reader', 'password' => 'test-password', 'company_id' => $this->companyId]);
        $permission = Permission::firstOrCreate(['name' => 'libre-solar-bms.view', 'guard_name' => 'api']);
        $user->givePermissionTo($permission);
        auth()->forgetGuards();
        app('tymon.jwt')->unsetToken();

        return $this->headers($user);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function withSequence(array $payload, int $sequence, string $kind): array {
        $payload['sequence'] = $sequence;
        $payload['message_id'] = $payload['node_id'].':'.$payload['session_id'].':'.$kind.':'.$sequence;

        return $payload;
    }
}
