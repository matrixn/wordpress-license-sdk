<?php

namespace Zion\WordPressLicense\Exceptions;

use RuntimeException;

class ApiException extends RuntimeException
{
    /** @param array<string, mixed> $details */
    public function __construct(
        string $message,
        public readonly string $errorCode = 'api_error',
        public readonly int $statusCode = 0,
        public readonly ?string $requestId = null,
        public readonly array $details = [],
    ) {
        parent::__construct($message, $statusCode);
    }
}
