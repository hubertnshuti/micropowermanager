<?php

namespace App\Plugins\LibreSolarBms\Console\Commands;

use Illuminate\Console\Command;

class InstallPackage extends Command {
    protected $signature = 'libre-solar-bms:install';
    protected $description = 'Install the read-only Libre Solar BMS plugin';

    public function handle(): int {
        $this->info('Libre Solar BMS plugin installed. Provision battery ownership before ingestion.');

        return self::SUCCESS;
    }
}
