<?php

declare(strict_types=1);

namespace App\Plugins\LibreSolarBms\Exceptions;

use App\Exceptions\MpmException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LibreSolarBmsException extends MpmException {
    /** @param list<array{path: string, code: string}> $errors */
    public function __construct(
        private readonly string $errorCode,
        int $status,
        private readonly ?string $messageId = null,
        private readonly array $errors = [],
    ) {
        parent::__construct($errorCode);
        $this->httpStatusCode = $status;
    }

    public function render(Request $request): JsonResponse {
        $body = ['result' => 'rejected', 'code' => $this->errorCode];
        if ($this->messageId !== null) {
            $body['message_id'] = $this->messageId;
        }
        if ($this->errors !== []) {
            $body['errors'] = $this->errors;
        }

        return response()->json($body, $this->httpStatusCode);
    }
}
