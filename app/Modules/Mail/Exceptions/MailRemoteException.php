<?php

declare(strict_types=1);

namespace App\Modules\Mail\Exceptions;

use RuntimeException;

/**
 * Fehler einer Gegenstelle (HTTP-Status, Transport, unerwartete Antwort). Trägt nie Secrets, nur maskierte Auszüge.
 */
final class MailRemoteException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $integration,
        public readonly ?int $httpStatus = null,
        public readonly ?string $responseExcerpt = null,
    ) {
        parent::__construct($message);
    }
}
