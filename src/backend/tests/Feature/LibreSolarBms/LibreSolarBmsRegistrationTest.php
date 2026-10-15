<?php

namespace Tests\Feature\LibreSolarBms;

use App\Models\MpmPlugin;
use App\Models\Plugins;
use App\Services\PluginsService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class LibreSolarBmsRegistrationTest extends TestCase {
    public function testPluginRegistersCentrallyAndEnablesForTheSelectedTenant(): void {
        $plugin = MpmPlugin::findOrFail(MpmPlugin::LIBRE_SOLAR_BMS);
        $this->assertSame('micro_power_manager', $plugin->getConnectionName());
        $this->assertSame('LibreSolarBms', $plugin->name);
        $this->assertSame('libre-solar-bms:install', $plugin->installation_command);

        $service = app(PluginsService::class);
        $service->enablePlugin($plugin->id);

        $this->assertTrue($service->isPluginActive($plugin->id));
        $this->assertSame('tenant', Plugins::where('mpm_plugin_id', $plugin->id)->firstOrFail()->getConnectionName());
        $this->assertSame(0, Artisan::call('libre-solar-bms:install'));
    }
}
