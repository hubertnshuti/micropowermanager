<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::connection('micro_power_manager')->table('api_keys', function (Blueprint $table): void {
            $table->timestamp('expires_at')->nullable();
        });
    }

    public function down(): void {
        Schema::connection('micro_power_manager')->table('api_keys', function (Blueprint $table): void {
            $table->dropColumn('expires_at');
        });
    }
};
