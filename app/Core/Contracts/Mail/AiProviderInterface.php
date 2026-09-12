<?php

declare(strict_types=1);

namespace App\Core\Contracts\Mail;

/**
 * Interner Adaptervertrag für strukturierte KI-Antworten. Kein Herstellerendpunkt. Eingaben werden vor dem Aufruf
 * maskiert (PromptMasker), Mailinhalte gelten ausschließlich als Daten, Ausgaben ausschließlich als Vorschläge und
 * werden gegen $schema validiert (SchemaValidator). Nicht eingerichtet: MailIntegrationNotConfiguredException.
 */
interface AiProviderInterface
{
    /**
     * @param  string  $task  Aufgabenschlüssel, z. B. classify_case, extract_change, draft_reply
     * @param  array<string, mixed>  $input  maskierte Eingabedaten
     * @param  array<string, mixed>  $schema  JSON-Schema der erwarteten Antwort
     * @return array<string, mixed> validierte Antwort plus meta (model, input_tokens, output_tokens, latency_ms, provider)
     */
    public function structured(string $task, array $input, array $schema): array;
}
