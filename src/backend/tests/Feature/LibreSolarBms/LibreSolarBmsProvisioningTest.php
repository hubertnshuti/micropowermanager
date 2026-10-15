<?php

namespace Tests\Feature\LibreSolarBms;

use App\Models\Company;
use App\Models\MpmPlugin;
use App\Models\Plugins;
use App\Plugins\LibreSolarBms\Models\Battery;
use App\Services\PluginsService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Mockery as TestDouble;
use RuntimeException as ProvisioningFailure;
use Tests\TestCase;

class LibreSolarBmsProvisioningTest extends TestCase {
    private string $directory;
    private string $keyFile;

    protected function setUp(): void {
        parent::setUp();
        $this->assertSame('mpm_testing', DB::connection('micro_power_manager')->getDatabaseName());
        DB::connection('micro_power_manager')->beginTransaction();
        $this->directory = storage_path('app/private/libre-solar-test-'.bin2hex(random_bytes(8)));
        mkdir($this->directory, 0700, true);
        $this->keyFile = $this->directory.'/adapter.key';
    }

    protected function tearDown(): void {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
        DB::connection('micro_power_manager')->rollBack();
        parent::tearDown();
    }

    public function testRepeatedProvisioningPreservesBatteryStateAndReusesOnePrivateKey(): void {
        $this->assertSame(0, $this->provision(['--label' => 'Original label']));
        $secret = file_get_contents($this->keyFile);
        $this->assertIsString($secret);
        $this->assertSame(0600, fileperms($this->keyFile) & 0777);
        $this->assertStringNotContainsString($secret, Artisan::output());
        $battery = Battery::where('node_id', '53494D0000000001')->firstOrFail();
        $summary = file_get_contents(base_path('tests/fixtures/LibreSolarBms/v1/valid/normal.summary.json'));
        DB::connection('tenant')->table('libre_solar_bms_battery_states')->insert(['battery_id' => $battery->id, 'summary' => $summary]);
        $permissions = DB::connection('tenant')->table('permissions')->count();
        $tail = DB::connection('tenant')->table('registration_tail')->count();
        $this->assertSame(0, $this->provision(['--label' => 'Replacement ignored']));
        $this->assertSame($secret, file_get_contents($this->keyFile));
        $this->assertStringNotContainsString($secret, Artisan::output());
        $this->assertSame(1, Battery::count());
        $this->assertSame('Original label', $battery->fresh()->label);
        $this->assertJsonStringEqualsJsonString($summary, DB::connection('tenant')->table('libre_solar_bms_battery_states')->value('summary'));
        $this->assertSame(1, Plugins::where('mpm_plugin_id', MpmPlugin::LIBRE_SOLAR_BMS)->count());
        $this->assertSame($permissions, DB::connection('tenant')->table('permissions')->count());
        $this->assertSame($tail, DB::connection('tenant')->table('registration_tail')->count());
        $key = Company::findOrFail($this->companyId)->apiKeys()->sole();
        $this->assertSame(hash('sha256', $secret), $key->token_hash);
        $this->assertTrue((bool) $key->active);
    }

    public function testInvalidCompanyOrPrivatePathDoesNotProvisionOrCreateKeys(): void {
        foreach ([
            ['company_id' => 0],
            ['company_id' => 999999999],
            ['--key-file' => '/tmp/libre-solar-outside-'.bin2hex(random_bytes(8)).'.key'],
            ['--key-file' => $this->directory.'/../../../../outside.key'],
            ['--key-file' => $this->directory.'/missing/adapter.key'],
        ] as $options) {
            $this->assertSame(1, $this->provision($options));
        }
        $this->assertSame(0, Battery::count());
        $this->assertSame(0, Company::findOrFail($this->companyId)->apiKeys()->count());
        $this->assertFileDoesNotExist($this->keyFile);
    }

    public function testProvisioningRejectsAdapterAndSourceRebindingWithoutChangingData(): void {
        $this->assertSame(0, $this->provision());
        $secret = file_get_contents($this->keyFile);
        foreach ([['--adapter' => 'another-adapter'], ['--source' => 'hardware']] as $options) {
            $this->assertSame(1, $this->provision($options));
        }
        $this->assertSame(1, Company::findOrFail($this->companyId)->apiKeys()->count());
        $this->assertSame($secret, file_get_contents($this->keyFile));
        $battery = Battery::sole();
        $this->assertSame('mpm-demo', $battery->adapter_id);
        $this->assertSame('simulator', $battery->source);
    }

    public function testExistingPrivateFileMustMatchAnActiveUnexpiredCompanyKey(): void {
        $this->assertSame(0, $this->provision());
        $secret = file_get_contents($this->keyFile);
        $key = Company::findOrFail($this->companyId)->apiKeys()->sole();
        chmod($this->keyFile, 0644);
        $this->assertSame(1, $this->provision());
        chmod($this->keyFile, 0600);
        $key->update(['active' => false]);
        $this->assertSame(1, $this->provision());
        $key->update(['active' => true, 'expires_at' => now()->subMinute()]);
        $this->assertSame(1, $this->provision());
        $this->assertSame($secret, file_get_contents($this->keyFile));
        $this->assertSame(1, Company::findOrFail($this->companyId)->apiKeys()->count());
        $this->assertSame(1, Battery::count());
    }

    public function testSymlinksAndKeysBelongingToAnotherCompanyAreRejected(): void {
        $other = Company::findOrFail($this->companyId)->replicate();
        $other->email = 'other-key-'.bin2hex(random_bytes(4)).'@example.com';
        $other->save();
        $secret = bin2hex(random_bytes(32));
        $other->apiKeys()->create(['token_hash' => hash('sha256', $secret), 'active' => true]);
        file_put_contents($this->keyFile, $secret);
        chmod($this->keyFile, 0600);
        $this->assertSame(1, $this->provision());
        $link = $this->directory.'/linked.key';
        symlink($this->keyFile, $link);
        $this->assertSame(1, $this->provision(['--key-file' => $link]));
        $this->assertSame($secret, file_get_contents($this->keyFile));
        $this->assertSame(0, Company::findOrFail($this->companyId)->apiKeys()->count());
        $this->assertSame(0, Battery::count());
    }

    public function testExpiryMustBeFutureUtcAndExistingExpiryIsNeverReset(): void {
        foreach (['2026-02-30T00:00:00Z', '2020-01-01T00:00:00Z', '2099-01-01T00:00:00+00:00', 'tomorrow'] as $expiry) {
            $this->assertSame(1, $this->provision(['--expires-at' => $expiry]));
        }
        $expiry = now()->utc()->addDay()->format('Y-m-d\TH:i:s\Z');
        $this->assertSame(0, $this->provision(['--expires-at' => $expiry]));
        $this->assertSame(0, $this->provision(['--expires-at' => $expiry]));
        $this->assertSame(1, $this->provision(['--expires-at' => now()->utc()->addDays(2)->format('Y-m-d\TH:i:s\Z')]));
        $key = Company::findOrFail($this->companyId)->apiKeys()->sole();
        $this->assertSame($expiry, $key->expires_at->utc()->format('Y-m-d\TH:i:s\Z'));
    }

    public function testFailureRollsBackTenantAndCentralWritesAndRemovesOnlyNewFile(): void {
        $plugins = TestDouble::mock(PluginsService::class);
        $plugins->shouldReceive('getByMpmPluginId')->twice()->andReturnNull();
        $plugins->shouldReceive('enablePlugin')->twice()->andThrow(new ProvisioningFailure('Injected provisioning failure'));
        $this->app->instance(PluginsService::class, $plugins);
        $this->assertSame(1, $this->provision());
        $this->assertFileDoesNotExist($this->keyFile);
        $this->assertSame(0, Battery::count());
        $this->assertSame(0, Company::findOrFail($this->companyId)->apiKeys()->count());
        $this->assertFalse(Plugins::where('mpm_plugin_id', MpmPlugin::LIBRE_SOLAR_BMS)->exists());
        $existingSecret = bin2hex(random_bytes(32));
        Company::findOrFail($this->companyId)->apiKeys()->create(['token_hash' => hash('sha256', $existingSecret), 'active' => true]);
        file_put_contents($this->keyFile, $existingSecret);
        chmod($this->keyFile, 0600);
        $this->assertSame(1, $this->provision());
        $this->assertSame($existingSecret, file_get_contents($this->keyFile));
        $this->assertSame(1, Company::findOrFail($this->companyId)->apiKeys()->count());
        $this->assertSame(0, Battery::count());
    }

    /** @param array<string, int|string> $options */
    private function provision(array $options = []): int {
        return Artisan::call('libre-solar-bms:provision', array_replace([
            'company_id' => $this->companyId, 'node_id' => '53494D0000000001', '--key-file' => $this->keyFile,
        ], $options));
    }
}
