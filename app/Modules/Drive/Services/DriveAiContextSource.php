<?php

declare(strict_types=1);

namespace App\Modules\Drive\Services;

use App\Modules\Ai\Contracts\AiContextSourceInterface;
use App\Modules\Cases\Models\MailCase;
use App\Modules\Drive\Models\DocumentReference;
use App\Modules\Security\Models\User;

/**
 * KI-Kontext aus Drive: nur Auszüge, die die handelnde Person lesen darf (DriveAccessGuard doppelt), begrenzt in
 * Anzahl und Länge. Ohne Berechtigung leere Liste. Auszüge gelten für die KI als untrusted.
 */
final class DriveAiContextSource implements AiContextSourceInterface
{
    public function __construct(private readonly DriveSearchService $search) {}

    public function excerptsFor(MailCase $case, User $user, int $maxExcerpts, int $maxChars): array
    {
        $result = [];

        $references = DocumentReference::query()
            ->withoutGlobalScopes()
            ->where('case_id', $case->getKey())
            ->where('source', 'drive')
            ->whereNull('deleted_at')
            ->whereNotNull('text_excerpt')
            ->orderByDesc('excerpt_indexed_at')
            ->limit(max(1, $maxExcerpts) * 2)
            ->get();

        foreach ($references as $reference) {
            if (! $reference instanceof DocumentReference) {
                continue;
            }

            if (count($result) >= $maxExcerpts) {
                break;
            }

            $excerpt = $this->search->authorizedExcerpt($user, $case, $reference);

            if ($excerpt === null) {
                continue;
            }

            $result[] = [
                'label' => (string) $reference->getAttribute('name'),
                'content' => mb_substr($excerpt, 0, max(1, $maxChars)),
                'source' => 'drive',
            ];
        }

        return $result;
    }
}
