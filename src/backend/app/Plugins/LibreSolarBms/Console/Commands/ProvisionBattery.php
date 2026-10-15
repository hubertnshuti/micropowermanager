<?php

namespace App\Plugins\LibreSolarBms\Console\Commands;

use App\Models\Company;
use App\Models\MpmPlugin;
use App\Models\Plugins;
use App\Plugins\LibreSolarBms\Models\Battery;
use App\Services\DatabaseProxyManagerService;
use App\Services\PluginsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class ProvisionBattery extends Command {
    protected $signature = 'libre-solar-bms:provision {company_id} {node_id}
        {--label=Battery} {--adapter=mpm-demo} {--source=simulator}';
    protected $description = 'Enable the plugin and provision one battery in an explicitly selected company';

    public function handle(DatabaseProxyManagerService $databases, PluginsService $plugins): int {
        $companyId = filter_var($this->argument('company_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $nodeId = (string) $this->argument('node_id');
        $adapter = (string) $this->option('adapter');
        $source = (string) $this->option('source');
        $label = (string) $this->option('label');
        if ($companyId === false || !Company::whereKey($companyId)->exists() || !preg_match('/^[0-9A-F]{16}$/D', $nodeId)
            || !preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D', $adapter) || !in_array($source, ['simulator', 'hardware'], true)
            || trim($label) === '' || mb_strlen($label) > 255) {
            $this->error('Select an existing company, uppercase hexadecimal node, valid adapter/source and a non-empty label.');

            return self::FAILURE;
        }

        return $databases->runForCompany($companyId, function () use ($companyId, $nodeId, $adapter, $source, $label, $plugins): int {
            return DB::connection('tenant')->transaction(function () use ($companyId, $nodeId, $adapter, $source, $label, $plugins): int {
                $battery = Battery::where('node_id', $nodeId)->first();
                if ($battery !== null && ($battery->adapter_id !== $adapter || $battery->source !== $source)) {
                    $this->error('The node is already bound to another adapter/source. Reconciliation must be explicit.');

                    return self::FAILURE;
                }
                $plugin = $plugins->getByMpmPluginId(MpmPlugin::LIBRE_SOLAR_BMS);
                if ($plugin === null) {
                    $plugins->enablePlugin(MpmPlugin::LIBRE_SOLAR_BMS);
                } else {
                    $plugin->update(['status' => Plugins::ACTIVE]);
                }
                Artisan::call('libre-solar-bms:install');
                $battery ??= Battery::create(['node_id' => $nodeId, 'label' => $label, 'adapter_id' => $adapter, 'source' => $source]);
                $this->info("Battery {$battery->id} provisioned for company {$companyId}; existing metrics and label preserved.");

                return self::SUCCESS;
            });
        });
    }
}
