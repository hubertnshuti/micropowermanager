<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::connection('tenant')->create('libre_solar_bms_batteries', function (Blueprint $table): void {
            $table->id();
            $table->string('node_id', 16)->unique();
            $table->string('label');
            $table->string('adapter_id', 64);
            $table->string('source', 16);
            $table->uuid('active_session_id')->nullable();
            $table->json('retired_sessions')->nullable();
            $table->timestamps();
        });
        Schema::connection('tenant')->create('libre_solar_bms_battery_states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('battery_id')->unique()->constrained('libre_solar_bms_batteries')->cascadeOnDelete();
            $table->json('summary')->nullable();
            $table->timestamp('summary_received_at', 3)->nullable();
        });
        Schema::connection('tenant')->create('libre_solar_bms_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('battery_id')->constrained('libre_solar_bms_batteries')->cascadeOnDelete();
            $table->string('message_id', 255)->collation('utf8mb4_bin');
            $table->char('fingerprint', 64);
            $table->string('result', 20);
            $table->string('reason', 40)->nullable();
            $table->timestamp('received_at', 3);
            $table->unique(['battery_id', 'message_id']);
        });
    }

    public function down(): void {
        Schema::connection('tenant')->dropIfExists('libre_solar_bms_receipts');
        Schema::connection('tenant')->dropIfExists('libre_solar_bms_battery_states');
        Schema::connection('tenant')->dropIfExists('libre_solar_bms_batteries');
    }
};
