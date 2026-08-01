<?php

namespace Zion\WordPressLicense\Exceptions;

final class ServerUnavailableException extends ApiException
{
    public function __construct(string $message = 'The Zion license server is unavailable.', ?string $requestId = null)
    {
        parent::__construct($message, 'server_unavailable', 503, $requestId);
    }
}
