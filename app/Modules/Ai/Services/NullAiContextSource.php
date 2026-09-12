<?php

declare(strict_types=1);

namespace App\Modules\Ai\Services;

use App\Modules\Ai\Contracts\AiContextSourceInterface;
use App\Modules\Cases\Models\MailCase;
use App\Modules\Security\Models\User;

/**
 * Standardbindung ohne Dokumentenquelle: keine Auszüge.
 */
final class NullAiContextSource implements AiContextSourceInterface
{
    public function excerptsFor(MailCase $case, User $user, int $maxExcerpts, int $maxChars): array
    {
        return [];
    }
}
