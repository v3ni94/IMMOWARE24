<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

class WriteBlockedException extends HubException
{
    public function __construct(string $message = 'Schreibpfad gesperrt.', public readonly ?string $operation = null)
    {
        parent::__construct($message);
    }
}
