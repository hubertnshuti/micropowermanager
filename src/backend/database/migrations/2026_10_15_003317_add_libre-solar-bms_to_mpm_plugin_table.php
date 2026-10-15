<?php

use App\Models\MpmPlugin;
use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void {
        DB::connection('micro_power_manager')->table('mpm_plugins')->insert([
            [
                'id' => MpmPlugin::LIBRE_SOLAR_BMS,
                'name' => 'LibreSolarBms',
                'description' => 'Read-only Libre Solar battery summaries, source status and fault events.',
                'installation_command' => 'libre-solar-bms:install',
                'root_class' => 'LibreSolarBms',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
        ]);
    }

    public function down(): void {
        DB::connection('micro_power_manager')->table('mpm_plugins')->where('id', MpmPlugin::LIBRE_SOLAR_BMS)->delete();
    }
};
