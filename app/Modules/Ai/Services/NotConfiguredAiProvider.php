<?php

declare(strict_types=1);

namespace App\Modules\Ai\Services;

use App\Core\Contracts\Mail\AiProviderInterface;
use App\Modules\Mail\Exceptions\MailIntegrationNotConfiguredException;

/**
 * Gebunden, solange kein KI-Provider konfiguriert ist ("KI nicht eingerichtet").
 */
final class NotConfiguredAiProvider implements AiProviderInterface
{
    public function structured(string $task, array $input, array $schema): array
    {
        throw MailIntegrationNotConfiguredException::for('ai');
    }
}
