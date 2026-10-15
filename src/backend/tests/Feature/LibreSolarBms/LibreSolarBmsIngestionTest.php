<?php

namespace Tests\Feature\LibreSolarBms;

use App\Models\ApiKey;
use App\Models\Auth\Permission;
use App\Models\Company;
use App\Models\MpmPlugin;
use App\Models\Plugins;
use App\Models\User;
use App\Plugins\LibreSolarBms\Models\Battery;
use App\Services\DatabaseProxyManagerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
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

    public function testBatteryPaginationRejectsMalformedOrExcessiveInputs(): void {
        $headers = $this->readerHeaders();
        foreach (['page=0', 'page=-1', 'page=1.5', 'page=1e2', 'page[]=1', 'per_page=0', 'per_page=101', 'per_page=25x', 'per_page=99999999999999999999999'] as $query) {
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
