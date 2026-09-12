<?php

declare(strict_types=1);

namespace App\Modules\Sync\Services\Replay;

use App\Modules\Sync\Models\ExternalPayload;

/**
 * Baut Spiegeldatensätze einer Entität aus einer archivierten Nutzlast (external_payloads) neu auf.
 * Die Implementierungen nutzen ausschließlich die öffentlichen Mirror-Services der Fachmodule.
 */
interface PayloadReplayerInterface
{
    /** Entitätsname aus SyncEntity (document, contact, calendar_event). */
    public function entityType(): string;

    /** payload_type der Nutzlasten, die dieser Replayer verarbeitet (02-data-model.md: vcard, ical, propfind_xml). */
    public function payloadType(): string;

    /**
     * Verarbeitet eine entpackte, maskierte Nutzlast. Wirft bei nicht verarbeitbarem Inhalt.
     */
    public function replay(ExternalPayload $payload, string $content): ReplayOutcome;

    /**
     * Schließt den Replay-Durchlauf ab (z. B. offene Sync-Läufe beenden). Wird genau einmal am Ende aufgerufen.
     */
    public function finish(): void;
}
