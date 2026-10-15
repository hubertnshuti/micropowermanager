<?php

use App\Plugins\LibreSolarBms\Http\Controllers\BatteryController;
use App\Plugins\LibreSolarBms\Http\Middleware\PluginAccess;
use Illuminate\Support\Facades\Route;

Route::prefix('libre-solar-bms')->group(function (): void {
    Route::middleware(['auth:api-key', PluginAccess::class])->group(function (): void {
        Route::post('ingest/summaries', [BatteryController::class, 'store'])->defaults('kind', 'summary');
        Route::post('ingest/events', [BatteryController::class, 'store'])->defaults('kind', 'event');
        Route::post('ingest/attributes', [BatteryController::class, 'store'])->defaults('kind', 'attributes');
        Route::post('ingest/status', [BatteryController::class, 'store'])->defaults('kind', 'status');
    });
    Route::middleware(['auth:api', PluginAccess::class])->group(function (): void {
        Route::get('batteries', [BatteryController::class, 'index']);
        Route::get('batteries/{id}', [BatteryController::class, 'show'])->whereNumber('id');
        Route::get('batteries/{id}/events', [BatteryController::class, 'events'])->whereNumber('id');
    });
});
