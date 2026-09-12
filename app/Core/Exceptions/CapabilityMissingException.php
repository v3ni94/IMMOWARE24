<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

class CapabilityMissingException extends HubException
{
    public function __construct(public readonly string $capability, ?string $message = null)
    {
        parent::__construct($message ?? sprintf('Fähigkeit "%s" ist nicht verfügbar oder nicht freigegeben.', $capability));
    }
}
