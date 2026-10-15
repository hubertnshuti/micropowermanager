<?php

namespace App\Plugins\LibreSolarBms\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider {
    public function boot(): void {
        Route::prefix('api')->middleware('api')->group(__DIR__.'/../routes/api.php');
    }
}
