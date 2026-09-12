<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

class RateLimitedException extends ConnectorException
{
    public function __construct(string $message = 'Rate Limit erreicht.', public readonly ?int $retryAfterSeconds = null)
    {
        parent::__construct($message);
    }
}
