<?php

declare(strict_types=1);

namespace App\Modules\Learning\Services;

use App\Core\Exceptions\ConnectorException;
use App\Modules\Connector\Models\ImmowareConnection;
use App\Modules\Documents\Http\WebDavClientFactory;
use App\Modules\Documents\Support\WebDavPath;
use Illuminate\Contracts\Config\Repository;
use Throwable;

/**
 * Lernphase Immoware24, Art webdav: erkundet lesend (PROPFIND) die Ordnerstruktur ab der Freigabewurzel, tiefer
 * und breiter als der laufende Sync (hub.documents.scan), damit auch Bereiche außerhalb der aktuell
 * konfigurierten Wurzeln (hub.documents.scan.roots) sichtbar werden. Kein GET, kein Schreiben. Nutzt denselben
 * WebDavClient wie der Dokumentenspiegel (öffentlicher Dienst des Moduls Documents).
 */
final class WebDavStructureScanner
{
    public function __construct(
        private readonly WebDavClientFactory $clients,
        private readonly Repository $config,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function scan(ImmowareConnection $connection): array
    {
        $client = $this->clients->forConnection($connection);
        $maxDepth = max(1, (int) $this->config->get('hub.learning.webdav.max_depth', 8));
        $maxFolders = max(1, (int) $this->config->get('hub.learning.webdav.max_folders', 800));
        $sampleSize = max(0, (int) $this->config->get('hub.learning.webdav.sample_filenames_per_folder', 8));
        $configuredRoots = array_map(static fn (string $root): string => WebDavPath::normalize($root), (array) $this->config->get('hub.documents.scan.roots', []));

        $folders = [];
        $queue = [['/', 0]];
        $visited = [];
        $truncated = false;

        while ($queue !== []) {
            [$path, $depth] = array_shift($queue);
            $normalized = WebDavPath::normalize($path);

            if (isset($visited[$normalized])) {
                continue;
            }

            $visited[$normalized] = true;

            if (count($folders) >= $maxFolders) {
                $truncated = true;

                break;
            }

            try {
                $result = $client->propfind($normalized, 1);
            } catch (Throwable $e) {
                if ($depth === 0) {
                    // Die Freigabewurzel muss erreichbar sein; ein Fehler hier ist ein Verbindungsproblem und
                    // lässt den gesamten Lauf fehlschlagen, statt einen leeren, scheinbar erfolgreichen Befund
                    // zu liefern. Fehler tiefer im Baum (einzelner Ordner nicht lesbar) werden nur vermerkt.
                    throw $e;
                }

                $folders[] = ['path' => $normalized, 'depth' => $depth, 'error' => $e->getMessage()];

                continue;
            }

            if (! $result->isMultistatus()) {
                // WebDavClient::propfind() wirft nur bei einem defekten 207; jeder andere Status (401, 404, 500 ...)
                // kommt unauffällig als PropfindResult zurück. Auf der Freigabewurzel gilt das als Verbindungsfehler.
                if ($depth === 0) {
                    throw new ConnectorException(sprintf('PROPFIND auf die Freigabewurzel lieferte Status %d statt 207.', $result->status));
                }

                $folders[] = ['path' => $normalized, 'depth' => $depth, 'error' => sprintf('PROPFIND lieferte Status %d.', $result->status)];

                continue;
            }

            $entryCount = 0;
            $extensions = [];
            $samples = [];

            foreach ($result->children() as $entry) {
                if ($entry->isCollection) {
                    if ($depth < $maxDepth) {
                        $queue[] = [$entry->path, $depth + 1];
                    }

                    continue;
                }

                $entryCount++;
                $extension = strtolower(pathinfo($entry->name(), PATHINFO_EXTENSION));
                $extensions[$extension !== '' ? $extension : '(ohne)'] = ($extensions[$extension !== '' ? $extension : '(ohne)'] ?? 0) + 1;

                if (count($samples) < $sampleSize) {
                    $samples[] = $entry->name();
                }
            }

            $folders[] = [
                'path' => $normalized,
                'depth' => $depth,
                'entry_count' => $entryCount,
                'extensions' => $extensions,
                'sample_names' => $samples,
                'in_configured_scope' => $this->isWithinConfiguredScope($normalized, $configuredRoots),
            ];
        }

        return [
            'kind' => 'webdav',
            'configured_roots' => $configuredRoots,
            'folders' => $folders,
            'folder_count' => count($folders),
            'truncated' => $truncated,
            'max_depth' => $maxDepth,
        ];
    }

    /**
     * WebDavPath::isWithin() gilt nur für echte Nachfahren eines Präfixes, nicht für den Ordner selbst
     * (Vertrag von isWithin, siehe Dokumentation dort). Ein konfigurierter Wurzelordner selbst gilt hier
     * ebenfalls als im Geltungsbereich, deshalb zusätzlich der Gleichheitsvergleich.
     *
     * @param  array<int, string>  $configuredRoots
     */
    private function isWithinConfiguredScope(string $path, array $configuredRoots): bool
    {
        foreach ($configuredRoots as $root) {
            if ($path === $root || WebDavPath::isWithin($path, $root)) {
                return true;
            }
        }

        return false;
    }
}
