<?php

declare(strict_types=1);

namespace App\Modules\Learning\Services;

use App\Modules\Learning\Enums\LearningKind;

/**
 * Vergleicht die Rohbefunde eines Lernlaufs mit dem vorigen erfolgreichen Lauf gleicher Art. Reine Funktion ohne
 * Seiteneffekte, damit der Vergleich ohne KI und ohne Netzwerkzugriff nachvollziehbar bleibt.
 */
final class LearningDiffer
{
    /**
     * @param  array<string, mixed>|null  $previous
     * @param  array<string, mixed>  $current
     * @return array<string, mixed>
     */
    public function diff(LearningKind $kind, ?array $previous, array $current): array
    {
        if ($previous === null) {
            return ['changed' => true, 'first_run' => true];
        }

        return match ($kind) {
            LearningKind::WebDav => $this->diffWebDav($previous, $current),
            LearningKind::CardDav, LearningKind::CalDav => $this->diffFieldUsage($previous, $current),
            LearningKind::Imports => $this->diffImports($previous, $current),
        };
    }

    /**
     * @param  array<string, mixed>  $previous
     * @param  array<string, mixed>  $current
     * @return array<string, mixed>
     */
    private function diffWebDav(array $previous, array $current): array
    {
        $previousPaths = $this->folderPaths($previous);
        $currentPaths = $this->folderPaths($current);

        $newFolders = array_values(array_diff($currentPaths, $previousPaths));
        $removedFolders = array_values(array_diff($previousPaths, $currentPaths));
        $newOutsideScope = array_values(array_filter($newFolders, static function (string $path) use ($current): bool {
            foreach ((array) ($current['folders'] ?? []) as $folder) {
                if (($folder['path'] ?? null) === $path) {
                    return ! (bool) ($folder['in_configured_scope'] ?? true);
                }
            }

            return false;
        }));

        return [
            'changed' => $newFolders !== [] || $removedFolders !== [],
            'new_folders' => $newFolders,
            'removed_folders' => $removedFolders,
            'new_folders_outside_configured_scope' => $newOutsideScope,
        ];
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return array<int, string>
     */
    private function folderPaths(array $facts): array
    {
        return array_values(array_map(static fn (array $folder): string => (string) ($folder['path'] ?? ''), (array) ($facts['folders'] ?? [])));
    }

    /**
     * CardDAV und CalDAV: Vergleich der Feldnutzungszahlen. Ein Feld gilt als neu aufgetreten, wenn es zuvor bei
     * 0 lag und jetzt befüllt ist; ein Feld gilt als weggefallen, wenn es zuvor befüllt war und jetzt bei 0 liegt.
     *
     * @param  array<string, mixed>  $previous
     * @param  array<string, mixed>  $current
     * @return array<string, mixed>
     */
    private function diffFieldUsage(array $previous, array $current): array
    {
        $previousUsage = (array) ($previous['field_usage'] ?? []);
        $currentUsage = (array) ($current['field_usage'] ?? []);

        $newlyUsed = [];
        $noLongerUsed = [];

        foreach ($currentUsage as $field => $count) {
            $before = (int) ($previousUsage[$field] ?? 0);

            if ($before === 0 && (int) $count > 0) {
                $newlyUsed[] = (string) $field;
            }
        }

        foreach ($previousUsage as $field => $count) {
            $after = (int) ($currentUsage[$field] ?? 0);

            if ((int) $count > 0 && $after === 0) {
                $noLongerUsed[] = (string) $field;
            }
        }

        $reachabilityChanged = (bool) ($previous['collection']['reachable'] ?? true) !== (bool) ($current['collection']['reachable'] ?? true);

        return [
            'changed' => $newlyUsed !== [] || $noLongerUsed !== [] || $reachabilityChanged,
            'newly_used_fields' => $newlyUsed,
            'no_longer_used_fields' => $noLongerUsed,
            'reachability_changed' => $reachabilityChanged,
        ];
    }

    /**
     * @param  array<string, mixed>  $previous
     * @param  array<string, mixed>  $current
     * @return array<string, mixed>
     */
    private function diffImports(array $previous, array $current): array
    {
        $previousKeys = array_keys((array) ($previous['formats'] ?? []));
        $currentKeys = array_keys((array) ($current['formats'] ?? []));
        $newFormats = array_values(array_diff($currentKeys, $previousKeys));

        $newDrafts = (int) ($current['draft_count'] ?? 0) > (int) ($previous['draft_count'] ?? 0);

        return [
            'changed' => $newFormats !== [] || $newDrafts,
            'new_format_keys' => $newFormats,
            'new_drafts_present' => $newDrafts,
        ];
    }
}
