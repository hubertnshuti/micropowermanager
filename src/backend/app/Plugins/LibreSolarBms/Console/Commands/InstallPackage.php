<?php

namespace App\Plugins\LibreSolarBms\Console\Commands;

use App\Models\Auth\Permission;
use App\Models\Auth\Role;
use Illuminate\Console\Command;

class InstallPackage extends Command {
    protected $signature = 'libre-solar-bms:install';
    protected $description = 'Install the read-only Libre Solar BMS plugin';

    public function handle(): int {
        $permission = Permission::firstOrCreate(['name' => 'libre-solar-bms.view', 'guard_name' => 'api']);
        foreach (Role::whereIn('name', ['owner', 'admin'])->where('guard_name', 'api')->get() as $role) {
            $role->givePermissionTo($permission);
        }
        $this->info('Libre Solar BMS plugin installed. Provision battery ownership before ingestion.');

        return self::SUCCESS;
    }
}
