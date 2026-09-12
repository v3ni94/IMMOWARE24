<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

class CircuitOpenException extends ConnectorException
{
    public function __construct(string $message = 'Circuit Breaker ist offen.', public readonly ?string $circuitKey = null)
    {
        parent::__construct($message);
    }
}
