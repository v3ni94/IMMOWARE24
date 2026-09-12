<?php

declare(strict_types=1);

namespace App\Modules\Mail\Exceptions;

use RuntimeException;

/**
 * Eine Integration (Gmail, Lexware, OpenAI, Drive) ist nicht eingerichtet. Die Oberfläche zeigt "Nicht eingerichtet",
 * nie einen Erfolg und nie einen technischen Fehler als Ergebnis.
 */
final class MailIntegrationNotConfiguredException extends RuntimeException
{
    public static function for(string $integration): self
    {
        return new self(sprintf('Integration %s ist nicht eingerichtet.', $integration));
    }
}
