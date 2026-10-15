<?php

namespace App\Services\ApiResolvers;

use App\Auth\ApiKeyGuard;
use App\Plugins\LibreSolarBms\Exceptions\LibreSolarBmsException;
use App\Services\Interfaces\IApiResolver;
use Illuminate\Http\Request;

class LibreSolarBmsApiResolver implements IApiResolver {
    public function resolveCompanyId(Request $request): int {
        /** @var ApiKeyGuard $guard */
        $guard = auth('api-key');
        $companyId = $guard->getCompanyId();
        if ($companyId === null) {
            throw new LibreSolarBmsException('invalid_credentials', 401);
        }

        return $companyId;
    }
}
