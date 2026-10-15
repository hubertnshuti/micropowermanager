<?php

namespace App\Plugins\LibreSolarBms\Http\Middleware;

use App\Models\Auth\Permission;
use App\Models\MpmPlugin;
use App\Plugins\LibreSolarBms\Exceptions\LibreSolarBmsException;
use App\Services\PluginsService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PluginAccess {
    public function handle(Request $request, callable $next): Response {
        if (!app(PluginsService::class)->isPluginActive(MpmPlugin::LIBRE_SOLAR_BMS)) {
            throw new LibreSolarBmsException('plugin_disabled', 403);
        }
        if ($request->is('api/libre-solar-bms/batteries*')) {
            $permission = Permission::where('name', 'libre-solar-bms.view')->where('guard_name', 'api')->first();
            if ($permission === null || !$request->user('api')?->checkPermissionTo($permission, 'api')) {
                throw new LibreSolarBmsException('forbidden', 403);
            }
        }

        return $next($request);
    }
}
