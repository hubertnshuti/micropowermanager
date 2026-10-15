<?php

namespace App\Plugins\LibreSolarBms\Http\Middleware;

use App\Plugins\LibreSolarBms\Exceptions\LibreSolarBmsException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateIngestionRequest {
    public function handle(Request $request, callable $next): Response {
        if (!$request->is('api/libre-solar-bms/ingest/*')) {
            return $next($request);
        }
        // Laravel's input-normalization middleware decodes JSON before route middleware runs.
        if (strlen($request->getContent()) > 32768) {
            throw new LibreSolarBmsException('payload_too_large', 413);
        }
        if (strtolower(trim(explode(';', $request->header('Content-Type', ''))[0])) !== 'application/json') {
            throw new LibreSolarBmsException('unsupported_media_type', 415);
        }

        return $next($request);
    }
}
