<?php

namespace Tests\Feature\LibreSolarBms;

use App\Plugins\LibreSolarBms\Models\Battery;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LibreSolarBmsMigrationTest extends TestCase {
    public function testTenantMigrationsRollBackAndReapplyWithoutChangingUnrelatedData(): void {
        $central = DB::connection('micro_power_manager');
        $this->assertSame('mpm_testing', $central->getDatabaseName());
        $this->assertSame(53306, (int) $central->getConfig('port'));
        $this->assertTrue(app()->environment('testing'));
        $centralTables = Schema::connection('micro_power_manager')->getTableListing($central->getDatabaseName());
        $previousTenant = config('database.connections.tenant');
        $database = 'LibreSolarBmsMigration_'.bin2hex(random_bytes(8));
        $central->statement('CREATE DATABASE `'.$database.'`');

        try {
            DB::purge('tenant');
            config(['database.connections.tenant' => [...config('database.connections.micro_power_manager'), 'database' => $database]]);
            $tenant = DB::connection('tenant');
            $this->assertSame($database, $tenant->getDatabaseName());
            $this->assertSame('tenant', (new Battery())->getConnectionName());
            Schema::connection('tenant')->create('unrelated_records', function (Blueprint $table): void {
                $table->id();
                $table->string('value');
            });
            $tenant->table('unrelated_records')->insert(['value' => 'preserve me']);
            $batteries = require database_path('migrations/tenant/2026_10_15_010000_create_libre_solar_bms_batteries.php');
            $events = require database_path('migrations/tenant/2026_10_15_020000_add_libre_solar_bms_events_and_status.php');

            $batteries->up();
            $events->up();
            $this->assertPluginSchema();
            $batteryId = $tenant->table('libre_solar_bms_batteries')->insertGetId([
                'node_id' => '53494D0000000001', 'label' => 'Disposable migration test',
                'adapter_id' => 'mpm-demo', 'source' => 'simulator',
            ]);
            $tenant->table('libre_solar_bms_battery_states')->insert(['battery_id' => $batteryId]);
            $state = $tenant->table('libre_solar_bms_battery_states')->first();
            $this->assertNotNull($state);
            $this->assertNull($state->summary);
            $this->assertNull($state->attributes);
            $this->assertNull($state->status);
            $this->assertNull($state->summary_received_at);
            $this->assertNull($state->status_received_at);
            $tenant->table('libre_solar_bms_battery_states')->update(['summary' => '{"soc":0,"soh":null}']);

            $events->down();
            $this->assertFalse(Schema::connection('tenant')->hasTable('libre_solar_bms_events'));
            $this->assertFalse(Schema::connection('tenant')->hasColumn('libre_solar_bms_battery_states', 'attributes'));
            $this->assertFalse(Schema::connection('tenant')->hasColumn('libre_solar_bms_battery_states', 'status'));
            $this->assertSame(['soc' => 0, 'soh' => null], json_decode($tenant->table('libre_solar_bms_battery_states')->value('summary'), true, flags: JSON_THROW_ON_ERROR));
            $batteries->down();
            foreach (['libre_solar_bms_batteries', 'libre_solar_bms_battery_states', 'libre_solar_bms_receipts'] as $table) {
                $this->assertFalse(Schema::connection('tenant')->hasTable($table));
            }
            $this->assertSame('preserve me', $tenant->table('unrelated_records')->value('value'));

            $batteries->up();
            $events->up();
            $this->assertPluginSchema();
            $this->assertSame(0, $tenant->table('libre_solar_bms_batteries')->count());
            $this->assertSame('preserve me', $tenant->table('unrelated_records')->value('value'));
            $this->assertSame($centralTables, Schema::connection('micro_power_manager')->getTableListing($central->getDatabaseName()));
        } finally {
            DB::purge('tenant');
            config(['database.connections.tenant' => $previousTenant]);
            $central->statement('DROP DATABASE `'.$database.'`');
        }
    }

    private function assertPluginSchema(): void {
        $schema = Schema::connection('tenant');
        foreach (['libre_solar_bms_batteries', 'libre_solar_bms_battery_states', 'libre_solar_bms_receipts', 'libre_solar_bms_events'] as $table) {
            $this->assertTrue($schema->hasTable($table));
        }
        $this->assertTrue($schema->hasIndex('libre_solar_bms_batteries', ['node_id'], 'unique'));
        $this->assertTrue($schema->hasIndex('libre_solar_bms_battery_states', ['battery_id'], 'unique'));
        $this->assertTrue($schema->hasIndex('libre_solar_bms_receipts', ['battery_id', 'message_id'], 'unique'));
        $this->assertTrue($schema->hasIndex('libre_solar_bms_events', ['battery_id', 'event_id'], 'unique'));
        $this->assertTrue($schema->hasIndex('libre_solar_bms_events', ['battery_id', 'observed_at', 'id']));
    }
}
