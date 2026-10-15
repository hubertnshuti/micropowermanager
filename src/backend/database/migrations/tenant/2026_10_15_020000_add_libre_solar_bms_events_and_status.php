<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::connection('tenant')->table('libre_solar_bms_battery_states', function (Blueprint $table): void {
            $table->json('attributes')->nullable();
            $table->json('status')->nullable();
            $table->timestamp('status_received_at', 3)->nullable();
        });
        Schema::connection('tenant')->create('libre_solar_bms_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('battery_id')->constrained('libre_solar_bms_batteries')->cascadeOnDelete();
            $table->string('event_id', 255)->collation('utf8mb4_bin');
            $table->json('payload');
            // Fixed-width UTC source times retain the full contract range and sort chronologically.
            $table->char('observed_at', 24)->collation('utf8mb4_bin');
            $table->timestamp('received_at', 3);
            $table->unique(['battery_id', 'event_id']);
            $table->index(['battery_id', 'observed_at', 'id']);
        });
    }

    public function down(): void {
        Schema::connection('tenant')->dropIfExists('libre_solar_bms_events');
        Schema::connection('tenant')->table('libre_solar_bms_battery_states', function (Blueprint $table): void {
            $table->dropColumn(['attributes', 'status', 'status_received_at']);
        });
    }
};
